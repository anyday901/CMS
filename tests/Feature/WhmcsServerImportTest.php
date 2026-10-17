<?php

namespace Tests\Feature;

use App\Import\Whmcs\ImportException;
use App\Import\Whmcs\ServerImporter;
use App\Import\Whmcs\ServiceLoginImporter;
use App\Import\Whmcs\WhmcsCrypt;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsServerImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $whmcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
    }

    public function test_servers_groups_products_and_logins_come_over_with_their_passwords(): void
    {
        $report = (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($this->whmcs, dryRun: false);

        $this->assertSame(['found' => 2, 'imported' => 1, 'skipped' => 1], $report->counts()['Servers']);
        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Server groups']);
        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Service logins']);
        $this->assertStringContainsString('Server "Old box" (interworx) uses a WHMCS module this app doesn\'t have.', $report->toMarkdown());

        $server = Server::sole();
        $this->assertSame(['cp1', 'cpanel', 'cp1.example.test', '203.0.113.5', 'root', WhmcsDatabase::SERVER_PASSWORD, 'ABCDEF', 300, true, true],
            [$server->name, $server->module, $server->hostname, $server->ip_address, $server->username, $server->password, $server->api_token, $server->max_accounts, $server->secure, $server->active]);

        $group = ServerGroup::sole();
        $this->assertSame(['Shared hosting', ServerGroup::FILL, [$server->id]], [$group->name, $group->fill_type, $group->servers->modelKeys()]);

        $product = Product::sole();
        $this->assertSame(['cpanel', ['package' => 'gold'], $group->id], [$product->module, $product->module_config, $product->server_group_id]);

        $service = Service::findOrFail(40);
        $this->assertSame([$server->id, 'ada', WhmcsDatabase::SERVICE_PASSWORD], [$service->server_id, $service->username, $service->password]);
        $this->assertNotNull($service->provisioned_at);

        // The account is set up, so the portal shows how to log in.
        $this->actingAs($service->client, 'client');
        $this->get('/portal/services/40')->assertOk()->assertSee('https://cp1.example.test:2083')->assertSee('ada')->assertSee(WhmcsDatabase::SERVICE_PASSWORD);
    }

    public function test_without_the_hash_logins_come_over_without_passwords(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertStringContainsString('No WHMCS encryption hash was given', $report->toMarkdown());
        $this->assertNull(Server::sole()->password);
        $service = Service::findOrFail(40);
        $this->assertSame(['ada', null], [$service->username, $service->password]);
    }

    public function test_a_wrong_hash_leaves_passwords_out_and_says_so(): void
    {
        $report = (new WhmcsImporter(null, 'not-the-hash'))->run($this->whmcs, dryRun: false);

        $this->assertStringContainsString('Some passwords didn\'t decrypt', $report->toMarkdown());
        $this->assertNull(Server::sole()->password);
        $this->assertNull(Service::findOrFail(40)->password);
    }

    public function test_a_server_staff_already_added_is_used_as_it_is(): void
    {
        $existing = Server::factory()->create(['name' => 'Mine', 'module' => 'cpanel', 'hostname' => 'cp1.example.test']);

        (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($this->whmcs, dryRun: false);

        $this->assertSame([$existing->id], Server::pluck('id')->all());
        $this->assertSame('Mine', $existing->fresh()->name);
        $this->assertSame($existing->id, Service::findOrFail(40)->server_id);
    }

    public function test_a_dry_run_counts_servers_and_saves_none(): void
    {
        $report = (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($this->whmcs, dryRun: true);

        $this->assertSame(1, $report->counts()['Servers']['imported']);
        $this->assertSame(0, Server::count());
        $this->assertSame(0, ServerGroup::count());
    }

    public function test_the_walkthrough_and_command_take_the_hash(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/settings/whmcs-import?step=2')->assertOk()->assertSee('WHMCS encryption hash');

        $this->artisan('whmcs:import', ['--dry-run' => true, '--hash' => WhmcsDatabase::HASH])->assertSuccessful();
        $this->assertSame(0, Server::count());
    }

    public function test_services_without_a_server_module_keep_their_whmcs_login(): void
    {
        WhmcsDatabase::seedPlainService($this->whmcs);

        $report = (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($this->whmcs, dryRun: false);

        $this->assertSame(['found' => 2, 'imported' => 2, 'skipped' => 0], $report->counts()['Service logins']);
        $service = Service::findOrFail(42);
        $this->assertSame(['ada-mail', WhmcsDatabase::MAILBOX_PASSWORD, null, null], [$service->username, $service->password, $service->server_id, $service->provisioned_at]);

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/services/42')->assertOk()->assertSee('Login')->assertSee('ada-mail')->assertSee(WhmcsDatabase::MAILBOX_PASSWORD);
        $this->put('/admin/services/42/login', ['username' => 'ada-box', 'password' => ''])->assertRedirect();
        $this->assertSame(['ada-box', WhmcsDatabase::MAILBOX_PASSWORD], [$service->fresh()->username, $service->fresh()->password]);
        // A service on a server changes its login with its server details instead.
        $this->put('/admin/services/40/login', ['username' => 'x'])->assertStatus(422);

        $this->actingAs($service->client, 'client');
        $this->get('/portal/services/42')->assertOk()->assertSee('Logging in')->assertSee('ada-box')->assertSee(WhmcsDatabase::MAILBOX_PASSWORD);
    }

    public function test_an_imported_install_gets_its_service_logins_by_running_again(): void
    {
        WhmcsDatabase::seedPlainService($this->whmcs);
        // An import from before every service kept its login, made without the hash.
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        Service::findOrFail(42)->forceFill(['username' => null])->save();
        // Staff set a password on this one since; it stays.
        Service::findOrFail(40)->forceFill(['password' => 'Staff-set-1'])->save();
        // A service added in WHMCS after the import isn't here.
        $this->whmcs->table('tblhosting')->insert(['id' => 43, 'userid' => 7, 'packageid' => 9, 'domain' => 'new.example.com', 'billingcycle' => 'Free Account', 'domainstatus' => 'Active', 'username' => 'ada-new']);

        $dryRun = ServiceLoginImporter::bringOver($this->whmcs, WhmcsDatabase::HASH, dryRun: true);
        $this->assertSame(1, $dryRun->counts()[ServiceLoginImporter::SECTION]['imported']);
        $this->assertNull(Service::findOrFail(42)->username);

        $report = ServiceLoginImporter::bringOver($this->whmcs, WhmcsDatabase::HASH, dryRun: false);
        $this->assertSame(['found' => 3, 'imported' => 1, 'skipped' => 1], $report->counts()[ServiceLoginImporter::SECTION]);
        $this->assertStringContainsString("Service 43 (new.example.com) isn't here.", $report->toMarkdown());
        $this->assertStringContainsString('1 service already had its login here', $report->toMarkdown());
        $this->assertSame(['ada-mail', WhmcsDatabase::MAILBOX_PASSWORD], [Service::findOrFail(42)->username, Service::findOrFail(42)->password]);
        $this->assertSame(['ada', 'Staff-set-1'], [Service::findOrFail(40)->username, Service::findOrFail(40)->password]);

        $again = ServiceLoginImporter::bringOver($this->whmcs, WhmcsDatabase::HASH, dryRun: false);
        $this->assertSame(0, $again->counts()[ServiceLoginImporter::SECTION]['imported']);
    }

    public function test_service_logins_go_only_to_the_same_client_as_in_whmcs(): void
    {
        WhmcsDatabase::seedPlainService($this->whmcs);
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        Client::find(7)->forceFill(['email' => 'ada@new.example.com'])->save();

        $report = ServiceLoginImporter::bringOver($this->whmcs, WhmcsDatabase::HASH, dryRun: false);

        $this->assertSame(0, $report->counts()[ServiceLoginImporter::SECTION]['imported']);
        $this->assertStringContainsString("isn't the one here (ada@new.example.com)", $report->toMarkdown());
        $this->assertNull(Service::findOrFail(42)->password);
    }

    public function test_without_the_hash_bringing_logins_over_brings_usernames_only(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        Service::findOrFail(40)->forceFill(['username' => null])->save();

        $report = ServiceLoginImporter::bringOver($this->whmcs, null, dryRun: false);

        $this->assertStringContainsString('only usernames came over', $report->toMarkdown());
        $this->assertSame(['ada', null], [Service::findOrFail(40)->username, Service::findOrFail(40)->password]);
    }

    public function test_bringing_logins_over_needs_an_install_imported_from_whmcs(): void
    {
        $this->expectException(ImportException::class);

        ServiceLoginImporter::bringOver($this->whmcs, WhmcsDatabase::HASH, dryRun: false);
    }

    public function test_the_command_brings_logins_over_on_its_own(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $path = sys_get_temp_dir().'/whmcs-logins-'.uniqid().'.md';

        $this->artisan('whmcs:import', ['--logins' => true, '--hash' => WhmcsDatabase::HASH, '--report' => $path])
            ->expectsConfirmation('Bring each service\'s WHMCS username and password over now?', 'yes')
            ->expectsOutputToContain('Logins brought over.')
            ->assertSuccessful();

        $this->assertSame(WhmcsDatabase::SERVICE_PASSWORD, Service::findOrFail(40)->password);
        unlink($path);
    }

    public function test_whmcs_module_names_map_to_this_apps_modules(): void
    {
        $this->assertSame(['cpanel', 'plesk', 'directadmin', 'proxmox', 'proxmox', null], array_map(ServerImporter::module(...), ['cpanel', 'Plesk', 'directadmin', 'proxmoxVPS', 'pvewhmcs', 'interworx']));
    }

    public function test_whmcs_encryption_round_trips_long_values(): void
    {
        $crypt = new WhmcsCrypt(WhmcsDatabase::HASH);
        $long = str_repeat('A long password-', 4);

        $this->assertSame($long, $crypt->decrypt($crypt->encrypt($long)));
        $this->assertNull($crypt->decrypt('not base64 !'));
        $this->assertNull((new WhmcsCrypt('other'))->decrypt($crypt->encrypt($long)));
    }
}
