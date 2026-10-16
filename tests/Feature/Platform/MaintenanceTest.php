<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/maintenance';

    private const MESSAGE = 'Moving to a new data centre until 10pm.';

    private const STORE = '/store';

    public function test_staff_turn_maintenance_mode_on_and_visitors_see_the_notice(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->put(self::URL, ['enabled' => '1', 'message' => self::MESSAGE])->assertRedirect(self::URL);
        $this->assertTrue(Activity::where('description', 'Turned maintenance mode on')->exists());

        // Staff still see the portal, with a reminder.
        $this->get(self::STORE)->assertOk()->assertSee('Maintenance mode is on.');
        $this->get('/admin')->assertOk()->assertSee('Maintenance mode is on: visitors see the maintenance notice.');

        auth('web')->logout();
        $this->get(self::STORE)->assertStatus(503)->assertSee(self::MESSAGE)->assertHeader('Retry-After', '600');
        $this->get('/portal/login')->assertStatus(503);
        $this->get('/knowledgebase')->assertStatus(503);

        // The admin area, the health check and payment webhooks keep working.
        $this->get('/admin/login')->assertOk();
        $this->getJson('/health')->assertJsonStructure(['status']);
        $this->post('/webhooks/paypal', [])->assertDontSee(self::MESSAGE);
    }

    public function test_the_standard_message_is_translated(): void
    {
        Settings::save(['maintenance.enabled' => true, 'help.languages' => ['de']]);

        $this->get(self::STORE, ['Accept-Language' => 'de'])->assertStatus(503)->assertSee('Wir sind gleich zurück');
    }

    public function test_turning_it_off_opens_the_portal_again(): void
    {
        Settings::save(['maintenance.enabled' => true]);
        $this->actingAs(User::factory()->create())->put(self::URL, ['enabled' => '0'])->assertSessionHas('status', 'Maintenance mode is off.');
        auth('web')->logout();

        $this->get(self::STORE)->assertOk();
    }

    public function test_maintenance_mode_needs_the_system_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-settings']]));
        $this->get(self::URL)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-system']]));
        $this->get(self::URL)->assertOk();
        // Without settings, the menu links straight to the system pages.
        $this->get(self::URL)->assertSee('/admin/settings/system', false);
    }
}
