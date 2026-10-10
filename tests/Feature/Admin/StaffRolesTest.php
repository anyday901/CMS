<?php

namespace Tests\Feature\Admin;

use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRolesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(StaffRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_existing_and_new_staff_default_to_admin(): void
    {
        $this->assertSame(StaffRole::Admin, User::factory()->create()->role);
    }

    public function test_support_can_look_but_not_change_anything(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $invoice->recalculate();

        $this->actingAs($this->staff(StaffRole::Support));

        $this->get('/admin/clients')->assertOk()->assertDontSee('Add client');
        $this->get("/admin/clients/{$client->id}")->assertOk()->assertDontSee('New invoice');
        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertDontSee('Record a payment')->assertSee('Download PDF');
        $this->get("/admin/invoices/{$invoice->id}/pdf")->assertOk();

        $this->get('/admin/clients/create')->assertForbidden();
        $this->put("/admin/clients/{$client->id}", ['first_name' => 'X'])->assertForbidden();
        $this->post("/admin/invoices/{$invoice->id}/payments", ['amount' => '10', 'method' => 'cash'])->assertForbidden();
        $this->post("/admin/invoices/{$invoice->id}/cancel")->assertForbidden();
        $this->get("/admin/clients/{$client->id}/invoices/create")->assertForbidden();
        $this->get('/admin/products')->assertForbidden();
        $this->get('/admin/activity')->assertForbidden();
        $this->get('/admin/staff')->assertForbidden();
    }

    public function test_billing_handles_clients_and_invoices_but_not_settings(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($this->staff(StaffRole::Billing));

        $this->get('/admin/clients/create')->assertOk();
        $this->get("/admin/clients/{$client->id}/invoices/create")->assertOk();
        $this->get('/admin')->assertOk()->assertDontSee('Products')->assertDontSee('Staff');
        $this->get('/admin/products')->assertForbidden();
        $this->get('/admin/tax-rules')->assertForbidden();
        $this->get('/admin/staff')->assertForbidden();
    }

    public function test_admin_manages_staff(): void
    {
        $admin = $this->staff(StaffRole::Admin);
        $this->actingAs($admin);

        $this->get('/admin/staff')->assertOk()->assertSee($admin->email);
        $this->post('/admin/staff', [
            'name' => 'Sam Support', 'email' => 'sam@example.com', 'role' => 'support',
            'password' => 'a-long-password', 'password_confirmation' => 'a-long-password',
        ])->assertRedirect('/admin/staff');

        $sam = User::where('email', 'sam@example.com')->sole();
        $this->assertSame(StaffRole::Support, $sam->role);
        $this->assertTrue(Activity::where('description', 'like', 'Added staff member Sam Support%')->exists());

        $oldHash = $sam->password;
        $this->put("/admin/staff/{$sam->id}", ['name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'billing', 'password' => ''])
            ->assertRedirect('/admin/staff');
        $this->assertSame(StaffRole::Billing, $sam->fresh()->role);
        $this->assertSame($oldHash, $sam->fresh()->password);

        $this->delete("/admin/staff/{$sam->id}")->assertRedirect('/admin/staff');
        $this->assertModelMissing($sam);
    }

    public function test_staff_must_have_a_strong_password_and_unique_email(): void
    {
        $admin = $this->staff(StaffRole::Admin);
        $this->actingAs($admin);

        $this->post('/admin/staff', ['name' => 'X', 'email' => $admin->email, 'role' => 'admin', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['email', 'password']);
        $this->post('/admin/staff', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'owner', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertSessionHasErrors('role');
    }

    public function test_admins_cannot_lock_themselves_or_everyone_out(): void
    {
        $admin = $this->staff(StaffRole::Admin);
        $this->actingAs($admin);

        $this->delete("/admin/staff/{$admin->id}")->assertSessionHasErrors('staff');
        $this->put("/admin/staff/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'support'])
            ->assertSessionHasErrors('role');
        $this->assertModelExists($admin);
        $this->assertSame(StaffRole::Admin, $admin->fresh()->role);

        // With a second admin, the other admin can be demoted and removed, but
        // the last one standing cannot.
        $other = $this->staff(StaffRole::Admin);
        $this->put("/admin/staff/{$other->id}", ['name' => $other->name, 'email' => $other->email, 'role' => 'billing'])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(StaffRole::Billing, $other->fresh()->role);
    }

    public function test_another_admin_can_be_removed_but_not_by_billing(): void
    {
        $admin = $this->staff(StaffRole::Admin);
        $other = $this->staff(StaffRole::Admin);

        $this->actingAs($this->staff(StaffRole::Billing));
        $this->delete("/admin/staff/{$other->id}")->assertForbidden();

        $this->actingAs($admin);
        $this->delete("/admin/staff/{$other->id}")->assertRedirect('/admin/staff');
        $this->assertModelMissing($other);
    }

    public function test_create_admin_command_takes_a_role(): void
    {
        $this->artisan('admin:create', ['--name' => 'Bea', '--email' => 'bea@example.com', '--password' => 'a-long-password', '--role' => 'billing'])
            ->assertSuccessful();
        $this->assertSame(StaffRole::Billing, User::where('email', 'bea@example.com')->sole()->role);

        $this->artisan('admin:create', ['--name' => 'Bad', '--email' => 'bad@example.com', '--password' => 'a-long-password', '--role' => 'owner'])
            ->assertFailed();
    }
}
