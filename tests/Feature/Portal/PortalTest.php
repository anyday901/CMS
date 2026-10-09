<?php

namespace Tests\Feature\Portal;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ClientResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Client $client, int $amount = 1000): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    public function test_guests_are_sent_to_portal_login(): void
    {
        $this->get('/portal')->assertRedirect('/portal/login');
        $this->get('/portal/invoices')->assertRedirect('/portal/login');
        $this->get('/portal/login')->assertOk()->assertSee('Log in to your account');
    }

    public function test_client_can_log_in_and_see_their_things(): void
    {
        $client = Client::factory()->create(['first_name' => 'Jane', 'password' => 'client-password']);
        Service::factory()->for($client)->create(['label' => 'my-vps']);
        $invoice = $this->invoiceFor($client, 1234);

        $this->post('/portal/login', ['email' => $client->email, 'password' => 'client-password'])
            ->assertRedirect('/portal');
        $this->assertAuthenticatedAs($client, 'client');

        $this->get('/portal')->assertOk()->assertSee('Welcome, Jane')->assertSee('my-vps')->assertSee('$12.34');
        $this->get('/portal/invoices')->assertOk()->assertSee('#'.$invoice->number);
        $this->get("/portal/invoices/{$invoice->id}")->assertOk()->assertSee('Hosting')->assertSee('Balance due');
        $this->get('/portal/services')->assertOk()->assertSee('my-vps');
    }

    public function test_clients_cannot_see_each_others_records(): void
    {
        $me = Client::factory()->create();
        $other = Client::factory()->create();
        $theirInvoice = $this->invoiceFor($other);
        $theirService = Service::factory()->for($other)->create();

        $this->actingAs($me, 'client');

        $this->get("/portal/invoices/{$theirInvoice->id}")->assertNotFound();
        $this->get("/portal/services/{$theirService->id}")->assertNotFound();
    }

    public function test_closed_clients_and_clients_without_password_cannot_log_in(): void
    {
        $closed = Client::factory()->create(['password' => 'client-password', 'status' => ClientStatus::Closed]);
        $noPassword = Client::factory()->create();

        $this->post('/portal/login', ['email' => $closed->email, 'password' => 'client-password'])->assertSessionHasErrors('email');
        $this->post('/portal/login', ['email' => $noPassword->email, 'password' => ''])->assertSessionHasErrors('password');
        $this->assertGuest('client');
    }

    public function test_client_closed_mid_session_is_signed_out(): void
    {
        $client = Client::factory()->create(['password' => 'client-password']);
        $this->post('/portal/login', ['email' => $client->email, 'password' => 'client-password', 'remember' => '1']);
        $this->assertAuthenticatedAs($client, 'client');

        $client->update(['status' => ClientStatus::Closed]);
        $this->app['auth']->forgetGuards(); // a new request loads the client fresh

        $this->get('/portal')->assertRedirect('/portal/login')->assertSessionHasErrors('email');
        $this->assertGuest('client');
        $this->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_staff_session_does_not_grant_portal_access_and_vice_versa(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/portal')->assertRedirect('/portal/login');

        auth()->guard('web')->logout();
        $this->actingAs(Client::factory()->create(), 'client');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_password_setup_flow(): void
    {
        Notification::fake();
        $client = Client::factory()->create();

        $this->post('/portal/forgot-password', ['email' => $client->email])->assertSessionHas('status');
        $this->post('/portal/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($client, ClientResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get("/portal/reset-password/{$token}?email={$client->email}")->assertOk();
        $this->post('/portal/reset-password', [
            'token' => $token, 'email' => $client->email,
            'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertRedirect('/portal/login');

        $this->post('/portal/login', ['email' => $client->email, 'password' => 'brand-new-password'])->assertRedirect('/portal');
    }

    public function test_admin_can_email_a_password_link(): void
    {
        Notification::fake();
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->post("/admin/clients/{$client->id}/password-link")->assertSessionHas('status');

        Notification::assertSentTo($client, ClientResetPassword::class);
    }

    public function test_account_details_and_password_change(): void
    {
        $client = Client::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($client, 'client');

        $this->put('/portal/account', [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'new@example.com', 'city' => 'Memphis',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Memphis', $client->refresh()->city);

        $this->put('/portal/account/password', [
            'current_password' => 'wrong', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors('current_password');

        $this->put('/portal/account/password', [
            'current_password' => 'old-password-123', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(password_verify('new-password-123', $client->refresh()->password));
    }

    public function test_clients_cannot_change_their_own_status_or_credit(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'client');

        $this->put('/portal/account', [
            'first_name' => 'A', 'last_name' => 'B', 'email' => $client->email,
            'status' => 'closed', 'credit_balance' => 99999,
        ]);

        $client->refresh();
        $this->assertSame(ClientStatus::Active, $client->status);
        $this->assertSame(0, $client->credit_balance);
    }
}
