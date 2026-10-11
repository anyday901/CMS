<?php

namespace Tests\Feature\Admin;

use App\Enums\ClientStatus;
use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginAsClientTest extends TestCase
{
    use RefreshDatabase;

    private const BANNER = 'from the admin area';

    public function test_staff_log_in_as_a_client_and_come_back(): void
    {
        $staff = User::factory()->create(['name' => 'Sam Staff']);
        $client = Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->actingAs($staff, 'web');

        $this->get("/admin/clients/{$client->id}")->assertSee('Log in as client');
        $this->post("/admin/clients/{$client->id}/login-as")->assertRedirect('/portal');
        $this->assertAuthenticatedAs($client, 'client');
        $this->get('/portal')->assertOk()->assertSee(self::BANNER)->assertSee('Jane Doe');

        // What staff do in the portal is logged under them.
        $this->put('/portal/account', ['first_name' => 'Janet', 'last_name' => 'Doe', 'email' => $client->email])->assertRedirect();
        $this->assertSame('Sam Staff', Activity::latest('id')->first()->actor_name);

        $this->post('/portal/back-to-admin')->assertRedirect("/admin/clients/{$client->id}");
        $this->assertGuest('client');
        $this->assertAuthenticatedAs($staff, 'web');
        $this->get('/admin')->assertOk();
        $this->assertTrue(Activity::where('description', 'Logged in to the portal as Jane Doe')->exists());
    }

    public function test_portal_log_out_only_leaves_the_client_account(): void
    {
        $staff = User::factory()->create();
        $client = Client::factory()->create();
        $client->forceFill(['remember_token' => 'their-own-device'])->save();
        $this->actingAs($staff, 'web');
        $this->post("/admin/clients/{$client->id}/login-as");

        $this->post('/portal/logout')->assertRedirect("/admin/clients/{$client->id}");
        $this->assertGuest('client');
        $this->assertAuthenticatedAs($staff, 'web');
        // The client stays remembered on their own devices.
        $this->assertSame('their-own-device', $client->fresh()->remember_token);
    }

    public function test_closed_clients_and_staff_without_client_access_are_refused(): void
    {
        $closed = Client::factory()->create(['status' => ClientStatus::Closed]);
        $this->actingAs(User::factory()->create(), 'web');
        $this->post("/admin/clients/{$closed->id}/login-as")->assertSessionHasErrors('client');
        $this->assertGuest('client');

        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');
        $this->post("/admin/clients/{$client->id}/login-as")->assertForbidden();
        $this->assertGuest('client');
    }

    public function test_clients_signed_in_normally_see_no_banner_and_cannot_use_back_to_admin(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'client');

        $this->get('/portal')->assertOk()->assertDontSee(self::BANNER);
        $this->post('/portal/back-to-admin')->assertRedirect('/portal');
        $this->assertAuthenticatedAs($client, 'client');
    }
}
