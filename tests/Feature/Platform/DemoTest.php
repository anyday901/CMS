<?php

namespace Tests\Feature\Platform;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_fills_in_a_believable_hosting_company(): void
    {
        Bus::fake();
        $this->seed(DemoSeeder::class);

        $staff = User::where('email', config('demo.staff_email'))->sole();
        $this->assertTrue(Hash::check(config('demo.password'), $staff->password));
        $client = Client::where('email', config('demo.client_email'))->sole();
        $this->assertTrue(Hash::check(config('demo.password'), $client->password));

        $this->assertGreaterThan(3, Client::count());
        $this->assertGreaterThan(0, Invoice::count());
        $this->assertGreaterThan(0, Ticket::count());

        $this->actingAs($staff)->get('/admin')->assertOk();
        $this->actingAs($client, 'client')->get('/portal')->assertOk();
    }

    public function test_demo_mode_shows_the_logins_and_refuses_risky_changes(): void
    {
        config(['demo.enabled' => true]);

        $this->get('/admin/login')->assertSee('Demo login: <strong>'.config('demo.staff_email'), false);
        $this->get(route('kb.index'))->assertSee('This is a demo. Sign in as '.config('demo.client_email'));

        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertSee('This is a demo. Look around and change anything');

        $this->from('/admin/settings/mail')->put('/admin/settings/mail', ['mailer' => 'log', 'from_address' => 'a@example.com', 'from_name' => 'A'])
            ->assertSessionHasErrors('demo');
        $this->from('/admin/settings/backups')->post('/admin/settings/backups')->assertSessionHasErrors('demo');
        $this->from('/admin/settings/maintenance')->put('/admin/settings/maintenance', ['enabled' => '1'])->assertSessionHasErrors('demo');
        $this->from('/admin/staff')->post('/admin/staff', ['name' => 'Eve', 'email' => 'eve@example.com', 'role' => 'admin'])->assertSessionHasErrors('demo');
        $this->assertFalse((bool) config('maintenance.enabled'));
    }

    public function test_demo_reset_refuses_to_wipe_a_real_install(): void
    {
        $this->artisan('demo:reset')->expectsOutputToContain('DEMO_MODE is off')->assertFailed();
    }
}
