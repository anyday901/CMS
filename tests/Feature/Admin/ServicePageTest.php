<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePageTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['first_name' => 'Carl', 'last_name' => 'Hoffman']);
        $this->service = Service::factory()->for($this->client)->for(Product::factory()->create(['name' => 'Mail box']))->create(['label' => 'carl.example']);
    }

    public function test_the_service_is_shown_inside_the_client_page_with_a_picker(): void
    {
        $other = Service::factory()->for($this->client)->for(Product::factory()->create(['name' => 'Backup plan']))->create();
        Service::factory()->for(Product::factory()->create(['name' => 'Someone else']))->create();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/services/{$this->service->id}")->assertOk()
            ->assertSee('Carl Hoffman')
            ->assertSee('aria-label="Client sections"', false)
            ->assertSee('Mail box - carl.example (Active)')
            ->assertSee('Backup plan (Active)')
            ->assertDontSee('Someone else')
            ->assertSeeInOrder(['Order', 'Product/Service', 'Server', 'Domain', 'Username', 'Password', 'Status', 'Registration date', 'Recurring amount', 'Next due date', 'Termination date', 'Billing cycle', 'Promotion code', 'Module commands']);

        $this->get("/admin/clients/{$this->client->id}?service={$other->id}")->assertRedirect("/admin/services/{$other->id}");
        // Another client's service isn't reached through this client.
        $elsewhere = Service::whereKeyNot([$this->service->id, $other->id])->sole();
        $this->get("/admin/clients/{$this->client->id}?service={$elsewhere->id}")->assertOk();
    }

    public function test_staff_edit_the_details_and_put_any_service_on_a_server(): void
    {
        $server = Server::factory()->create(['name' => 'mail1']);
        $this->actingAs(User::factory()->create(), 'web');

        $this->put("/admin/services/{$this->service->id}/details", [
            'server_id' => $server->id,
            'label' => 'carl.example',
            'username' => 'carl',
            'password' => '',
            'registration_date' => '2025-12-15',
            'next_due_date' => '2027-01-15',
            'recurring_amount' => '120.50',
            'billing_cycle' => 'annually',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved.');

        $service = $this->service->fresh();
        $this->assertSame([$server->id, 'carl', '2025-12-15', '2027-01-15', 12050, BillingCycle::Annually],
            [$service->server_id, $service->username, $service->registration_date->toDateString(), $service->next_due_date->toDateString(), $service->recurring_amount, $service->billing_cycle]);
        $this->assertEqualsCanonicalizing(['server_id', 'username', 'registration_date', 'next_due_date', 'recurring_amount', 'billing_cycle'],
            Activity::where('description', 'like', 'Changed the details%')->sole()->properties['changed']);

        $this->get("/admin/services/{$service->id}")->assertSee('mail1')->assertSee('value="120.50"', false);
    }

    public function test_details_are_checked(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->put("/admin/services/{$this->service->id}/details", ['next_due_date' => '', 'recurring_amount' => '-1', 'billing_cycle' => 'weekly', 'server_id' => 999])
            ->assertSessionHasErrors(['recurring_amount', 'billing_cycle', 'server_id']);
        $this->put("/admin/services/{$this->service->id}/details", ['next_due_date' => '', 'billing_cycle' => 'monthly'])
            ->assertSessionHasErrors(['next_due_date' => 'A recurring service needs a next due date.']);
        // A one-time service needs none.
        $this->put("/admin/services/{$this->service->id}/details", ['next_due_date' => '', 'billing_cycle' => 'one_time'])->assertSessionHasNoErrors();
        $this->assertNull($this->service->fresh()->next_due_date);
    }

    public function test_staff_who_cannot_manage_clients_see_the_details_without_editing(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->get("/admin/services/{$this->service->id}")->assertOk()->assertSee('Next due date')->assertDontSee('Save changes')->assertDontSee('Module commands');
        $this->put("/admin/services/{$this->service->id}/details", ['recurring_amount' => '1.00'])->assertForbidden();
    }
}
