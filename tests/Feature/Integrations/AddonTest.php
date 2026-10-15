<?php

namespace Tests\Feature\Integrations;

use App\Addons\AddonManager;
use App\Models\InstalledAddon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeAddon;
use Tests\TestCase;

class AddonTest extends TestCase
{
    use RefreshDatabase;

    private const ADDONS_URL = '/admin/settings/addons';

    private const FAKE_ADDON_URL = '/admin/settings/addons/fake';

    private const FAKE_INSTALL_URL = '/admin/settings/addons/fake/install';

    private const FAKE_PAGE_URL = '/admin/fake';

    protected function setUp(): void
    {
        parent::setUp();

        config(['addons.available' => [FakeAddon::class]]);
        $this->app->forgetInstance(AddonManager::class);
        app(AddonManager::class)->boot();
        app('router')->getRoutes()->refreshNameLookups();
        $this->actingAs(User::factory()->create());
    }

    public function test_staff_install_configure_enable_and_disable_an_addon(): void
    {
        $this->get(self::ADDONS_URL)->assertOk()->assertSee('Fake add-on')->assertSee('Not installed');
        $this->get(self::FAKE_PAGE_URL)->assertNotFound();

        $this->post(self::FAKE_INSTALL_URL)->assertRedirect(self::FAKE_ADDON_URL);
        $this->assertTrue(Schema::hasTable('fake_addon_rows'));
        $this->assertSame('1.2.0', InstalledAddon::sole()->version);
        $this->get(self::FAKE_PAGE_URL)->assertNotFound();

        $this->get(self::FAKE_ADDON_URL)->assertOk()->assertSee('Greeting');
        $this->put(self::FAKE_ADDON_URL, ['greeting' => str_repeat('x', 21)])->assertSessionHasErrors('greeting');
        $this->put(self::FAKE_ADDON_URL, ['greeting' => 'Hi', 'api_key' => 'k-123', 'loud' => '1', 'colour' => 'blue'])->assertSessionHas('status');
        $this->assertSame(['greeting' => 'Hi', 'api_key' => 'k-123', 'loud' => true, 'colour' => 'blue'], InstalledAddon::sole()->settings);
        $this->assertStringNotContainsString('k-123', InstalledAddon::sole()->getRawOriginal('settings'));

        // A blank secret keeps the saved one, which isn't shown.
        $this->get(self::FAKE_ADDON_URL)->assertDontSee('k-123');
        $this->put(self::FAKE_ADDON_URL, ['greeting' => 'Hey', 'api_key' => '', 'colour' => 'red']);
        $this->assertSame('k-123', InstalledAddon::sole()->settings['api_key']);

        $this->post('/admin/settings/addons/fake/enable')->assertSessionHas('status');
        $this->get(self::FAKE_PAGE_URL)->assertOk()->assertSee('Hey from the fake add-on');
        $this->get('/admin')->assertSee('Fake');

        $this->post('/admin/settings/addons/fake/disable');
        $this->get(self::FAKE_PAGE_URL)->assertNotFound();
    }

    public function test_enabled_addons_boot_and_hear_events(): void
    {
        $manager = app(AddonManager::class);
        $manager->install('fake');
        $manager->setEnabled('fake', true);
        FakeAddon::$booted = 0;

        $this->app->forgetInstance(AddonManager::class);
        app(AddonManager::class)->boot();

        $this->assertSame(1, FakeAddon::$booted);
    }

    public function test_uninstall_keeps_or_deletes_the_data(): void
    {
        $manager = app(AddonManager::class);
        $manager->install('fake');

        $this->delete(self::FAKE_ADDON_URL)->assertRedirect(self::ADDONS_URL);
        $this->assertSame(0, InstalledAddon::count());
        $this->assertTrue(Schema::hasTable('fake_addon_rows'));

        // Installing again finds the tables already there.
        $this->post(self::FAKE_INSTALL_URL);
        $this->delete(self::FAKE_ADDON_URL, ['remove_data' => '1']);
        $this->assertFalse(Schema::hasTable('fake_addon_rows'));
    }

    public function test_an_addon_cant_be_installed_twice_or_found_when_unknown(): void
    {
        app(AddonManager::class)->install('fake');

        $this->post(self::FAKE_INSTALL_URL)->assertSessionHasErrors('addon');
        $this->post('/admin/settings/addons/nope/install')->assertNotFound();
        $this->get('/admin/settings/addons/nope')->assertNotFound();
    }

    public function test_a_newer_version_runs_its_migrations_on_update(): void
    {
        app(AddonManager::class)->install('fake');
        InstalledAddon::where('key', 'fake')->update(['version' => '1.0.0']);

        $this->get(self::ADDONS_URL)->assertSee('Version 1.2.0 is available.');
        $this->post('/admin/settings/addons/fake/upgrade')->assertSessionHas('status');
        $this->assertSame('1.2.0', InstalledAddon::sole()->version);
    }
}
