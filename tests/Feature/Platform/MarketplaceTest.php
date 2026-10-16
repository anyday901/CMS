<?php

namespace Tests\Feature\Platform;

use App\Addons\AddonManager;
use App\Models\User;
use App\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/marketplace';

    private const BASE = 'https://market.example.com';

    private const INSTALL = self::URL.'/install';

    private const UPLOAD = self::URL.'/upload';

    private const APPEARANCE = '/admin/settings/appearance';

    private const ZIP = 'application/zip';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/cms-marketplace-'.uniqid();
        File::ensureDirectoryExists($this->root.'/themes');
        config([
            'themes.path' => $this->root.'/themes',
            'marketplace.addons_path' => $this->root.'/addons',
            'marketplace.registry_url' => self::BASE.'/registry.json',
        ]);
        app(ThemeManager::class)->forget();

        // The registry as the docs workflow publishes it, served from a fake host.
        $this->artisan('marketplace:registry', ['source' => base_path('marketplace'), 'output' => $this->root.'/site', '--base-url' => self::BASE])->assertSuccessful();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_starts_with($request->url(), self::BASE.'/')
            && is_file($file = $this->root.'/site/'.substr($request->url(), strlen(self::BASE) + 1))
            ? Http::response(file_get_contents($file))
            : Http::response('', 404));

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        app(ThemeManager::class)->forget();
        app(AddonManager::class)->refresh();

        parent::tearDown();
    }

    public function test_staff_browse_the_registry_and_install_a_theme(): void
    {
        $this->get(self::URL)->assertOk()->assertSee('Status board')->assertSee('Sunrise');

        $this->from(self::URL)->post(self::INSTALL, ['type' => 'theme', 'key' => 'sunrise'])
            ->assertRedirect(self::URL)->assertSessionHas('status', 'Sunrise 1.0.0 is installed. Pick it under Appearance to use it.');

        $this->assertFileExists($this->root.'/themes/sunrise/theme.json');
        $this->get(self::APPEARANCE)->assertSee('Sunrise');
        $this->get(self::URL)->assertSee('Installed');
    }

    public function test_an_add_on_is_installed_and_then_shows_under_add_ons(): void
    {
        $this->from(self::URL)->post(self::INSTALL, ['type' => 'addon', 'key' => 'status-board'])->assertSessionHasNoErrors();

        $this->assertFileExists($this->root.'/addons/status-board/src/StatusBoardAddon.php');
        $this->assertTrue(app(AddonManager::class)->available()->has('status-board'));

        $this->delete(self::URL.'/addon/status-board')->assertSessionHas('status', 'Removed.');
        $this->assertDirectoryDoesNotExist($this->root.'/addons/status-board');
    }

    public function test_a_download_that_doesnt_match_its_checksum_is_refused(): void
    {
        $registry = json_decode(file_get_contents($this->root.'/site/registry.json'), true);
        $registry['packages'][1]['sha256'] = str_repeat('a', 64);
        file_put_contents($this->root.'/site/registry.json', json_encode($registry));

        $this->from(self::URL)->post(self::INSTALL, ['type' => 'theme', 'key' => 'sunrise'])
            ->assertSessionHasErrors(['marketplace' => 'The download doesn\'t match the checksum in the marketplace, so it wasn\'t installed.']);
        $this->assertDirectoryDoesNotExist($this->root.'/themes/sunrise');
    }

    public function test_a_package_zip_can_be_uploaded(): void
    {
        $zip = $this->root.'/site/packages/sunrise-1.0.0.zip';

        $this->from(self::URL)->post(self::UPLOAD, ['package' => new UploadedFile($zip, 'sunrise.zip', self::ZIP, null, true)])
            ->assertSessionHasNoErrors();
        $this->assertFileExists($this->root.'/themes/sunrise/assets/theme.css');

        // The active theme can't be removed.
        $this->put(self::APPEARANCE, ['theme' => 'sunrise', 'company_name' => 'Example Hosting']);
        $this->delete(self::URL.'/theme/sunrise')->assertSessionHasErrors('marketplace');
        $this->put(self::APPEARANCE, ['theme' => 'harbor', 'company_name' => 'Example Hosting']);
        $this->delete(self::URL.'/theme/sunrise')->assertSessionHas('status', 'Removed.');
        $this->delete(self::URL.'/theme/lighthouse')->assertSessionHasErrors('marketplace');
    }

    public function test_packages_that_escape_their_folder_or_lack_a_manifest_are_refused(): void
    {
        $evil = $this->zip('evil.zip', ['theme.json' => '{"key":"evil","name":"Evil","version":"1.0.0"}', '../../escaped.txt' => 'x']);
        $this->from(self::URL)->post(self::UPLOAD, ['package' => new UploadedFile($evil, 'evil.zip', self::ZIP, null, true)])->assertSessionHasErrors('package');
        $this->assertFileDoesNotExist($this->root.'/escaped.txt');
        $this->assertDirectoryDoesNotExist($this->root.'/themes/evil');

        $this->from(self::URL)->post(self::UPLOAD, ['package' => UploadedFile::fake()->create('big.zip', 7681, self::ZIP)])->assertSessionHasErrors('package');

        $empty = $this->zip('empty.zip', ['readme.txt' => 'Nothing here']);
        $this->from(self::URL)->post(self::UPLOAD, ['package' => new UploadedFile($empty, 'empty.zip', self::ZIP, null, true)])->assertSessionHasErrors('package');

        $reserved = $this->zip('harbor.zip', ['theme.json' => '{"key":"harbor","name":"Fake Harbor","version":"1.0.0"}']);
        $this->from(self::URL)->post(self::UPLOAD, ['package' => new UploadedFile($reserved, 'harbor.zip', self::ZIP, null, true)])->assertSessionHasErrors('package');

        $hijack = $this->zip('hijack.zip', ['addon.json' => '{"key":"hijack","name":"Hijack","version":"1.0.0","class":"App\\\\Hijack","autoload":{"psr-4":{"App\\\\":"src/"}}}']);
        $this->from(self::URL)->post(self::UPLOAD, ['package' => new UploadedFile($hijack, 'hijack.zip', self::ZIP, null, true)])->assertSessionHasErrors('package');
        $this->assertDirectoryDoesNotExist($this->root.'/addons/hijack');
    }

    public function test_installs_can_be_turned_off(): void
    {
        config(['marketplace.installs' => false]);

        $this->post(self::INSTALL, ['type' => 'theme', 'key' => 'sunrise'])->assertForbidden();
        $this->get(self::URL)->assertOk()->assertSee('Sunrise');
    }

    public function test_an_unreachable_registry_still_shows_the_page(): void
    {
        config(['marketplace.registry_url' => self::BASE.'/missing.json']);

        $this->get(self::URL.'?refresh=1')->assertOk()->assertSee('read the marketplace at '.self::BASE.'/missing.json');
    }

    public function test_the_registry_command_needs_an_https_base_url(): void
    {
        $this->artisan('marketplace:registry', ['source' => base_path('marketplace'), 'output' => $this->root.'/other', '--base-url' => 'http://insecure.example.com'])->assertFailed();
    }

    private function zip(string $name, array $files): string
    {
        $path = $this->root.'/'.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $file => $contents) {
            $zip->addFromString($file, $contents);
        }
        $zip->close();

        return $path;
    }
}
