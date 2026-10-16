<?php

namespace Tests\Feature\Servers;

use App\Import\Whmcs\WhmcsImporter;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Models\WhmcsModule;
use App\Provisioning\Converted\ConvertedSsoServerModule;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use App\Provisioning\Whmcs\ModuleInspector;
use App\Provisioning\Whmcs\WhmcsSsoServerModule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;
use ZipArchive;

class WhmcsModuleTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/whmcs-modules';

    private const FIXTURE = __DIR__.'/../../Fixtures/whmcs/servers';

    private const META = ['display_name' => 'Demo Panel', 'requires_server' => true, 'ports' => ['secure' => 2222, 'plain' => 2221], 'config_fields' => []];

    private const SERVER_CODE = '<?php function %s_CreateAccount($params) { return "success"; } function %s_SuspendAccount($params) { return "success"; }';

    private string $root;

    private string $converted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/cms-whmcs-modules-'.uniqid();
        // Converted classes are loaded once per test run, so they are always written to the same place.
        $this->converted = sys_get_temp_dir().'/cms-converted-modules-fixture';
        File::deleteDirectory($this->converted);
        config(['provisioning.whmcs_modules_path' => $this->root, 'provisioning.converted_modules_path' => $this->converted]);
        $GLOBALS['demopanel_calls'] = [];
        $GLOBALS['demopanel_replies'] = [];
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_an_uploaded_php_file_is_checked_and_stays_off_until_enabled(): void
    {
        $this->upload('demopanel.php', (string) file_get_contents(self::FIXTURE.'/demopanel/demopanel.php'))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'demopanel is uploaded. Read its check below, then enable it.');

        $module = WhmcsModule::sole();
        $this->assertFalse($module->enabled);
        $this->assertFileExists($this->root.'/demopanel/demopanel.php');
        $this->assertSame(['MetaData', 'ConfigOptions', 'CreateAccount', 'SuspendAccount', 'TerminateAccount', 'ChangePassword', 'TestConnection', 'ServiceSingleSignOn'], $module->report['functions']);
        $this->assertSame(['reply', 'AdminCustomButtonArray', 'reboot', 'ClientArea'], $module->report['unsupported']);
        $this->assertSame([], $module->report['warnings']);

        // Not offered on products until it is enabled.
        $this->assertFalse(app(ModuleRegistry::class)->all()->has('whmcs_demopanel'));
        $this->get(self::URL)->assertOk()->assertSee('demopanel')->assertSee('Enable')->assertSee('The check found nothing that won');
    }

    public function test_a_zip_of_the_modules_folder_installs_the_server_module_and_warns_about_what_wont_work(): void
    {
        $code = sprintf(self::SERVER_CODE, 'acme', 'acme')
            .' function acme_TerminateAccount($p) { $row = WHMCS\Database\Capsule::table("tblhosting")->find(1); localAPI("GetClients"); return decrypt($p["password"]); }';

        $this->upload('acme.zip', [
            'modules/servers/acme/acme.php' => $code,
            'modules/servers/acme/hooks.php' => '<?php add_hook("ClientAreaPage", 1, function () {});',
            'modules/servers/acme/templates/overview.tpl' => '{$domain}',
            'modules/addons/acme/acme.php' => '<?php function acme_config() { return []; } function acme_output($vars) {}',
        ])->assertSessionHasNoErrors();

        $module = WhmcsModule::sole();
        $this->assertSame('acme', $module->name);
        $this->assertFileExists($this->root.'/acme/hooks.php');
        $this->assertFileDoesNotExist($this->root.'/acme/modules');
        $this->assertSame([
            'The zip also had modules/addons/acme, which wasn\'t installed: only the server module is used.',
            'It reads WHMCS\'s own database tables (tblhosting), which don\'t exist here, so those parts will fail.',
            'It calls the WHMCS internal API (localAPI), which isn\'t available here and always returns an error.',
            'It calls encrypt() or decrypt(). Here those are Laravel\'s, which can\'t read values WHMCS encrypted.',
            'Its WHMCS hooks (hooks.php, add_hook) aren\'t loaded.',
            'Its client area templates (.tpl) aren\'t shown. Clients see the usual service page.',
        ], $module->report['warnings']);
        $this->get(self::URL)->assertSee('Things that may not work')->assertSee('tblhosting');
    }

    public function test_modules_that_cant_work_here_are_refused_with_the_reason(): void
    {
        $cases = [
            ['gw.zip', ['modules/gateways/gw.php' => '<?php function gw_config() {} function gw_link($p) {}', 'modules/gateways/callback/gw.php' => '<?php'], 'That is a WHMCS payment gateway module.'],
            ['notes.php', '<?php function notes_config() { return []; } function notes_activate() {} function notes_output($vars) {}', 'It is a WHMCS add-on module. Only server (provisioning) modules can be uploaded so far.'],
            ['reg.php', '<?php function reg_getConfigArray() {} function reg_RegisterDomain($p) {}', 'It is a WHMCS registrar module.'],
            ['locked.php', "<?php //0046b\nif(!extension_loaded('ionCube Loader')){die('The ionCube Loader is needed');}", 'It is encoded (with ionCube or similar)'],
            ['broken.php', '<?php function broken_CreateAccount($p) { return "success" }', 'broken.php has a PHP error on line 1'],
            ['helper.php', '<?php function something_else() {}', 'helper.php has no helper_CreateAccount function'],
            ['my-panel.php', '<?php', '"my-panel" isn\'t a WHMCS module name.'],
            ['two.zip', ['servers/one/one.php' => sprintf(self::SERVER_CODE, 'one', 'one'), 'servers/two/two.php' => sprintf(self::SERVER_CODE, 'two', 'two')], 'The zip has more than one module (one, two).'],
            ['readme.txt', 'hello', 'The module field must have one of the following extensions'],
        ];

        foreach ($cases as [$name, $content, $error]) {
            $this->upload($name, $content)->assertSessionHasErrors('module');
            $this->assertStringContainsString($error, session('errors')->first('module'), $name);
        }

        $this->assertSame(0, WhmcsModule::count());
        $this->assertSame([], File::glob($this->root.'/*'));
    }

    public function test_enabling_loads_the_module_in_its_own_process_and_offers_it_on_products(): void
    {
        $this->upload('demopanel.php', (string) file_get_contents(self::FIXTURE.'/demopanel/demopanel.php'));
        Process::fake(['*' => Process::result(json_encode([...self::META, 'config_fields' => ['configoption1' => ['label' => 'Package Name', 'default' => 'basic']]]))]);

        $this->post(self::URL.'/'.WhmcsModule::sole()->id.'/enable')->assertSessionHasNoErrors();

        Process::assertRan(fn ($process) => array_slice($process->command, 1, 2) === ['artisan', 'whmcs:inspect-module'] && $process->command[3] === $this->root.'/demopanel/demopanel.php');
        $module = WhmcsModule::sole();
        $this->assertTrue($module->enabled);
        $this->assertSame('Demo Panel', $module->display_name);

        $wrapped = app(ModuleRegistry::class)->find('whmcs_demopanel');
        $this->assertInstanceOf(WhmcsSsoServerModule::class, $wrapped);
        $this->assertSame([2222, 2221], [$wrapped->defaultPort(true), $wrapped->defaultPort(false)]);
        $this->get('/admin/products/create')->assertOk()->assertSee('Demo Panel (WHMCS): Package Name')->assertSee('value="basic"', false);
    }

    public function test_a_module_that_fails_to_load_isnt_enabled(): void
    {
        $this->upload('demopanel.php', (string) file_get_contents(self::FIXTURE.'/demopanel/demopanel.php'));
        Process::fake(['*' => Process::result('', 'PHP Fatal error:  Uncaught Error: Undefined constant "ROOTDIR"', 255)]);

        $this->post(self::URL.'/'.WhmcsModule::sole()->id.'/enable')->assertSessionHasErrors('whmcs_module');

        $this->assertStringContainsString('The module failed when it was loaded: PHP Fatal error:  Uncaught Error: Undefined constant "ROOTDIR"', session('errors')->first('whmcs_module'));
        $this->assertFalse(WhmcsModule::sole()->enabled);
    }

    public function test_the_inspect_command_reads_metadata_and_config_options(): void
    {
        $this->assertSame(0, Artisan::call('whmcs:inspect-module', ['file' => self::fixture().'/demopanel/demopanel.php']));

        $this->assertSame([
            'display_name' => 'Demo Panel',
            'requires_server' => true,
            'ports' => ['secure' => 2222, 'plain' => 2221],
            'config_fields' => [
                'configoption1' => ['label' => 'Package Name', 'help' => 'The package on the panel', 'default' => 'basic'],
                'configoption2' => ['label' => 'Shell Access', 'help' => 'Tick to grant access', 'type' => 'select', 'options' => ['' => 'No', 'on' => 'Yes']],
                'configoption3' => ['label' => 'Region', 'type' => 'select', 'options' => ['eu' => 'eu', 'us' => 'us'], 'default' => 'us'],
                'configoption4' => ['label' => 'Notes', 'type' => 'textarea'],
            ],
        ], json_decode(trim(Artisan::output()), true));

        $this->assertSame(1, Artisan::call('whmcs:inspect-module', ['file' => $this->root.'/missing/missing.php']));
        $this->assertStringContainsString('The WHMCS module\'s files are missing.', json_decode(trim(Artisan::output()), true)['error']);
    }

    public function test_actions_call_the_module_with_whmcs_params_and_report_its_answer(): void
    {
        [$service, $server] = $this->demopanelService();
        Schema::create('mod_demopanel', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id');
            $table->string('username');
        });

        $module = app(ModuleRegistry::class)->find('whmcs_demopanel');
        $result = $module->create($service);

        $this->assertTrue($result->ok, (string) $result->message);
        [$function, $params] = $GLOBALS['demopanel_calls'][0];
        $service->refresh();
        $this->assertSame('CreateAccount', $function);
        $this->assertSame('example', $service->username);
        $this->assertSame([$service->id, 'example.com', 'example', $service->password, 'gold', 'on', '', 'Ada', 'Lovelace', 'ada@example.test'],
            [$params['serviceid'], $params['domain'], $params['username'], $params['password'], $params['configoption1'], $params['configoption2'], $params['configoption24'], $params['clientsdetails']['firstname'], $params['clientsdetails']['lastname'], $params['clientsdetails']['email']]);
        $this->assertSame([true, $server->id, 'panel1.example.test', '203.0.113.9', 'root', 'serverpass', 'HASH', true, 'https', 2222],
            [$params['server'], $params['serverid'], $params['serverhostname'], $params['serverip'], $params['serverusername'], $params['serverpassword'], $params['serveraccesshash'], $params['serversecure'], $params['serverhttpprefix'], $params['serverport']]);
        $this->assertDatabaseHas('mod_demopanel', ['service_id' => $service->id, 'username' => 'example']);

        // An error message from the module is the action's failure; a thrown error too.
        $GLOBALS['demopanel_replies']['SuspendAccount'] = 'Account not found on the panel';
        $this->assertSame([false, 'Account not found on the panel'], [($r = $module->suspend($service))->ok, $r->message]);
        $GLOBALS['demopanel_replies']['TerminateAccount'] = new RuntimeException('Connection refused');
        $this->assertSame([false, 'The module failed in TerminateAccount: Connection refused'], [($r = $module->terminate($service))->ok, $r->message]);

        // Functions the module doesn't have change nothing.
        $this->assertSame([true, 'The module has no UnsuspendAccount function, so nothing was changed on the server.'], [($r = $module->unsuspend($service))->ok, $r->message]);
        $this->assertTrue($module->canChangePassword($service));
        $this->assertTrue($module->changePassword($service)->ok);

        $GLOBALS['demopanel_replies']['ServiceSingleSignOn'] = ['success' => true, 'redirectTo' => 'https://panel1.example.test:2222/sso?token=abc'];
        $this->assertSame('https://panel1.example.test:2222/sso?token=abc', $module->loginUrl($service, '198.51.100.1'));

        $GLOBALS['demopanel_replies']['TestConnection'] = ['success' => false, 'error' => 'Bad login'];
        $this->assertSame([false, 'Bad login'], [($r = $module->testConnection($server))->ok, $r->message]);
    }

    public function test_a_new_password_reaches_the_module_and_is_kept_when_it_works(): void
    {
        [$service] = $this->demopanelService();
        $service->forceFill(['provisioned_at' => now(), 'username' => 'example', 'password' => 'OldPass1!', 'new_password' => 'NewPass2!'])->save();

        app(Provisioner::class)->queue($service, 'change_password');

        [$function, $params] = end($GLOBALS['demopanel_calls']);
        $this->assertSame(['ChangePassword', 'NewPass2!'], [$function, $params['password']]);
        $this->assertSame(['NewPass2!', null], [$service->refresh()->password, $service->new_password]);
    }

    public function test_a_service_on_another_modules_server_isnt_touched(): void
    {
        [$service] = $this->demopanelService();
        $service->forceFill(['server_id' => Server::factory()->create(['module' => 'cpanel'])->id])->save();

        $result = app(ModuleRegistry::class)->find('whmcs_demopanel')->create($service->refresh());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('isn\'t a Demo Panel (WHMCS) server', (string) $result->message);
        $this->assertSame([], $GLOBALS['demopanel_calls']);
    }

    public function test_a_module_in_use_cant_be_turned_off_or_removed(): void
    {
        [$service] = $this->demopanelService();
        $module = WhmcsModule::sole();

        $this->post(self::URL."/{$module->id}/disable")->assertSessionHasErrors('whmcs_module');
        $this->delete(self::URL."/{$module->id}")->assertSessionHasErrors('whmcs_module');
        $this->assertTrue($module->refresh()->enabled);

        $service->product->update(['module' => null]);
        Server::query()->delete();
        $this->post(self::URL."/{$module->id}/disable")->assertSessionHasNoErrors();
        $this->delete(self::URL."/{$module->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, WhmcsModule::count());
    }

    public function test_converting_writes_a_cms_module_and_moves_products_and_servers_to_it(): void
    {
        [$service, $server] = $this->demopanelService();
        Schema::create('mod_demopanel', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id');
            $table->string('username');
        });
        Process::fake(['*' => Process::result('{"key":"demopanel"}')]);

        $this->post(self::URL.'/'.WhmcsModule::sole()->id.'/convert')->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Demo Panel is now the DemopanelModule module, and its products and servers use it. Download it to keep the code with your site.');

        Process::assertRan(fn ($process) => array_slice($process->command, 2) === ['whmcs:check-converted', $process->command[3], 'DemopanelModule']);
        $code = (string) file_get_contents($this->converted.'/demopanel/DemopanelModule.php');
        foreach ([
            'class DemopanelModule extends ConvertedSsoServerModule',
            "public function configFields(): array\n    {\n        return [\n            'package_name' => [",
            'protected function createAccount(array $params)',
            "if (\$params['package_name'] === '') {",
            "DB::table('mod_demopanel')->insert(",
            '$this->logModuleCall(\'demopanel\', \'CreateAccount\'',
            "return \$this->reply('CreateAccount', \$params);",
            'protected function changeAccountPassword(array $params)',
            'protected function singleSignOn(array $params)',
            "// WHMCS's ClientArea: CMS doesn't call it.\n    private function clientArea(array \$params)",
        ] as $expected) {
            $this->assertStringContainsString($expected, $code);
        }
        $this->assertStringNotContainsString('Capsule', $code);
        $this->assertStringNotContainsString("defined('WHMCS')", $code);

        // Products and servers moved over, with settings by name; the WHMCS module is gone.
        $this->assertSame(['demopanel', ['package_name' => 'gold', 'shell_access' => 'on']], [$service->product->refresh()->module, $service->product->module_config]);
        $this->assertSame('demopanel', $server->refresh()->module);
        $this->assertSame(0, WhmcsModule::count());
        $this->assertDirectoryDoesNotExist(sys_get_temp_dir().'/cms-whmcs-module-fixture/demopanel');

        // The converted module runs the copied code with the same $params, settings by name too.
        $module = app(ModuleRegistry::class)->find('demopanel');
        $this->assertInstanceOf(ConvertedSsoServerModule::class, $module);
        $this->assertSame('Demo Panel', $module->label());
        $this->assertTrue($module->create($service->refresh())->ok);
        [$function, $params] = end($GLOBALS['demopanel_calls']);
        $this->assertSame(['CreateAccount', 'gold', 'gold', 'demopanel', 2222], [$function, $params['package_name'], $params['configoption1'], $params['moduletype'], $params['serverport']]);
        $this->assertDatabaseHas('mod_demopanel', ['service_id' => $service->id]);
        $GLOBALS['demopanel_replies']['ServiceSingleSignOn'] = ['success' => true, 'redirectTo' => 'https://panel1.example.test/sso'];
        $this->assertSame('https://panel1.example.test/sso', $module->loginUrl($service, '198.51.100.1'));

        // The class loads on its own, as the check before it was put in place does.
        $this->assertSame(0, Artisan::call('whmcs:check-converted', ['file' => $this->converted.'/demopanel/DemopanelModule.php', 'class' => 'DemopanelModule']));
        $this->assertSame('demopanel', json_decode(trim(Artisan::output()), true)['key']);

        $this->get(self::URL)->assertSee('Converted modules')->assertSee('DemopanelModule')->assertSee('In use by products or servers');
        $this->get('/admin/products/'.$service->product_id.'/edit')->assertOk()->assertSee('Demo Panel: Package Name')->assertSee('value="gold"', false);
        $download = $this->get('/admin/settings/converted-modules/demopanel/download')->assertOk();
        $zip = new ZipArchive;
        $zip->open($download->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->locateName('demopanel/DemopanelModule.php'));
        $this->assertNotFalse($zip->locateName('demopanel/module.json'));
        $zip->close();

        $this->delete('/admin/settings/converted-modules/demopanel')->assertSessionHasErrors('whmcs_module');
        $this->assertDirectoryExists($this->converted.'/demopanel');
    }

    public function test_modules_that_cant_be_rewritten_or_arent_enabled_arent_converted(): void
    {
        $this->demopanelService();
        $module = WhmcsModule::sole();
        $module->update(['enabled' => false]);

        $this->post(self::URL."/{$module->id}/convert")->assertSessionHasErrors('whmcs_module');
        $this->assertSame('Enable the module before converting it, so CMS knows its settings.', session('errors')->first('whmcs_module'));

        $folder = $this->root.'/spaced';
        File::ensureDirectoryExists($folder);
        File::put("{$folder}/spaced.php", "<?php\nnamespace Vendor\\Spaced;\nfunction spaced_CreateAccount(\$p) { return 'success'; }");
        config(['provisioning.whmcs_modules_path' => $this->root]);
        $spaced = WhmcsModule::create(['name' => 'spaced', 'display_name' => 'Spaced', 'enabled' => true, 'meta' => self::META, 'report' => ['functions' => ['CreateAccount']]]);

        $this->post(self::URL."/{$spaced->id}/convert")->assertSessionHasErrors('whmcs_module');
        $this->assertStringContainsString('declares a namespace, so it can\'t be converted', session('errors')->first('whmcs_module'));
        $this->assertSame([], File::glob($this->converted.'/*'));
        $this->assertTrue($spaced->refresh()->enabled);
    }

    public function test_uploads_can_be_turned_off(): void
    {
        config(['provisioning.whmcs_uploads' => false]);

        $this->get(self::URL)->assertOk()->assertSee('WHMCS_MODULE_UPLOADS=false');
        $this->upload('demopanel.php', '<?php')->assertForbidden();
    }

    public function test_the_whmcs_import_puts_products_and_servers_on_a_converted_module(): void
    {
        File::ensureDirectoryExists($this->converted.'/theirpanel');
        File::put($this->converted.'/theirpanel/module.json', json_encode(['key' => 'theirpanel', 'class' => 'TheirpanelModule', 'file' => 'TheirpanelModule.php', 'whmcs_name' => 'cpanel', 'settings' => ['configoption1' => 'package']]));
        File::put($this->converted.'/theirpanel/TheirpanelModule.php', '<?php class TheirpanelModule extends App\Provisioning\Converted\ConvertedServerModule {
            public function key(): string { return "theirpanel"; }
            public function label(): string { return "Their panel"; }
            public function whmcsName(): string { return "cpanel"; }
            public function configFields(): array { return ["package" => ["label" => "Package"]]; }
            public function settingPositions(): array { return [1 => "package"]; }
            public function defaultPort(bool $secure): int { return 2087; }
            public function needsDomain(): bool { return true; }
            protected function createAccount(array $params) { return "success"; }
        }');
        $whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($whmcs);

        (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($whmcs, dryRun: false);

        $this->assertSame('theirpanel', Server::sole()->module);
        $this->assertSame(['theirpanel', ['package' => 'gold']], [Product::sole()->module, Product::sole()->module_config]);
        $this->assertSame(Server::sole()->id, Service::findOrFail(40)->server_id);
    }

    public function test_the_whmcs_import_puts_products_and_servers_on_an_uploaded_module(): void
    {
        WhmcsModule::create(['name' => 'cpanel', 'display_name' => 'cPanel (theirs)', 'enabled' => true, 'meta' => self::META, 'report' => ['functions' => ['CreateAccount']]]);
        $whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($whmcs);

        (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($whmcs, dryRun: false);

        $server = Server::sole();
        $this->assertSame('whmcs_cpanel', $server->module);
        $this->assertSame(['whmcs_cpanel', ['configoption1' => 'gold']], [Product::sole()->module, Product::sole()->module_config]);
        $this->assertSame([$server->id, 'ada'], [Service::findOrFail(40)->server_id, Service::findOrFail(40)->username]);
    }

    /**
     * An enabled demopanel module (the fixture) with a service on one of its servers.
     *
     * @return array{Service, Server}
     */
    private function demopanelService(): array
    {
        $fixture = self::fixture();
        config(['provisioning.whmcs_modules_path' => $fixture]);
        Artisan::call('whmcs:inspect-module', ['file' => $fixture.'/demopanel/demopanel.php']);
        WhmcsModule::create(['name' => 'demopanel', 'display_name' => 'Demo Panel', 'enabled' => true, 'meta' => json_decode(trim(Artisan::output()), true),
            'report' => ModuleInspector::inspect($fixture.'/demopanel', 'demopanel')]);
        $server = Server::factory()->create(['name' => 'panel1', 'module' => 'whmcs_demopanel', 'hostname' => 'panel1.example.test', 'ip_address' => '203.0.113.9', 'username' => 'root', 'password' => 'serverpass', 'api_token' => 'HASH', 'port' => null]);
        $product = Product::factory()->create(['module' => 'whmcs_demopanel', 'module_config' => ['configoption1' => 'gold', 'configoption2' => 'on']]);
        $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
        $service = Service::factory()->for($product)->for($client)->pending()->create(['label' => 'example.com']);
        $service->forceFill(['server_id' => $server->id])->save();

        return [$service, $server];
    }

    /**
     * A copy of the fixture modules, always at the same path so PHP loads
     * their functions once for all the tests, and remade if a test removed it.
     */
    private static function fixture(): string
    {
        $path = sys_get_temp_dir().'/cms-whmcs-module-fixture';
        File::copyDirectory(self::FIXTURE, $path);

        return $path;
    }

    /** @param  string|array<string, string>  $content  a file's content, or a zip's files */
    private function upload(string $name, string|array $content)
    {
        $path = sys_get_temp_dir().'/'.uniqid().'-'.$name;

        if (is_array($content)) {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE);
            foreach ($content as $file => $body) {
                $zip->addFromString($file, $body);
            }
            $zip->close();
        } else {
            file_put_contents($path, $content);
        }

        return $this->from(self::URL)->post(self::URL, ['module' => new UploadedFile($path, $name, null, null, true)]);
    }
}
