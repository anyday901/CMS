<?php

namespace Tests\Feature\Admin;

use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const CASEY = 'casey@example.com';

    private const STAFF = '/admin/staff';

    private function save(array $data, ?User $user = null): TestResponse
    {
        $data += ['name' => 'Casey', 'email' => self::CASEY, 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'];

        return $user ? $this->put(self::STAFF."/{$user->id}", $data) : $this->post(self::STAFF, $data);
    }

    public function test_custom_staff_get_only_the_abilities_ticked(): void
    {
        $this->actingAs(User::factory()->create());

        $this->save(['role' => 'custom', 'permissions' => ['manage-billing', 'own-everything']])->assertSessionHasErrors('permissions.1');
        $this->save(['role' => 'custom', 'permissions' => ['manage-billing', 'manage-tickets']])->assertRedirect(self::STAFF);

        $casey = User::where('email', self::CASEY)->sole();
        $this->assertSame(StaffRole::Custom, $casey->role);
        $this->assertSame(['manage-billing', 'manage-tickets'], $casey->permissions);
        $this->get(self::STAFF)->assertSee('2 permissions');

        $client = Client::factory()->create();
        $this->actingAs($casey);
        $this->get("/admin/clients/{$client->id}/invoices/create")->assertOk();
        $this->get('/admin/tickets')->assertOk();
        $this->get('/admin/clients/create')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
        $this->get(self::STAFF)->assertForbidden();
    }

    public function test_custom_with_nothing_ticked_can_only_look(): void
    {
        $casey = User::factory()->create(['role' => StaffRole::Custom, 'permissions' => null]);
        $this->actingAs($casey);

        $this->get('/admin/clients')->assertOk();
        $this->get('/admin/tickets')->assertForbidden();
        $this->get('/admin/clients/create')->assertForbidden();
    }

    public function test_permissions_are_dropped_when_moving_to_a_built_in_role(): void
    {
        $this->actingAs(User::factory()->create());
        $casey = User::factory()->create(['email' => self::CASEY, 'role' => StaffRole::Custom, 'permissions' => ['manage-clients']]);

        $this->save(['role' => 'support', 'permissions' => ['manage-staff'], 'password' => '', 'password_confirmation' => ''], $casey)->assertRedirect(self::STAFF);

        $casey->refresh();
        $this->assertSame(StaffRole::Support, $casey->role);
        $this->assertNull($casey->permissions);
        $this->assertFalse($casey->allows('manage-staff'));
        $this->assertTrue($casey->allows('manage-tickets'));
    }
}
