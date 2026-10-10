<?php

namespace Tests\Feature;

use App\Billing\PaymentRecorder;
use App\Billing\RefundService;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_actions_are_logged_with_who_did_them(): void
    {
        $staff = User::factory()->create(['name' => 'Alex Admin', 'password' => 'a-long-password']);

        $this->post('/admin/login', ['email' => $staff->email, 'password' => 'a-long-password'])->assertRedirect();
        $this->post('/admin/clients', ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'status' => 'active']);
        $client = Client::sole();

        $login = Activity::where('description', 'Staff logged in')->sole();
        $this->assertSame(['staff', $staff->id, 'Alex Admin'], [$login->actor_type, $login->actor_id, $login->actor_name]);
        $this->assertSame('127.0.0.1', $login->ip_address);

        $created = Activity::where('description', 'Created client')->sole();
        $this->assertSame($client->id, $created->client_id);
        $this->assertTrue($created->subject->is($client));
    }

    public function test_billing_events_are_logged_against_the_client(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $invoice->recalculate();

        $payment = app(PaymentRecorder::class)->record($invoice, 1000, 'cash', 'R-1');
        app(RefundService::class)->refund($payment, 400, 'credit');

        $entries = Activity::where('client_id', $client->id)->orderBy('id')->pluck('description')->all();
        $this->assertContains("Payment of \$10.00 by Cash on invoice #{$invoice->number}", $entries);
        $this->assertContains("Refunded \$4.00 of a Cash payment to account credit on invoice #{$invoice->number}", $entries);

        // No one is signed in, so the scheduler or a webhook gets the credit.
        $this->assertSame(['system'], Activity::distinct()->pluck('actor_type')->all());
    }

    public function test_service_actions_and_portal_logins_are_logged(): void
    {
        $client = Client::factory()->create(['password' => 'client-password']);
        $service = Service::factory()->for($client)->create(['status' => 'active']);

        $this->actingAs(User::factory()->create(), 'web');
        $this->post("/admin/services/{$service->id}/suspend")->assertRedirect();
        $this->assertTrue(Activity::where('client_id', $client->id)->where('description', 'like', 'Suspended%')->exists());

        $this->app['auth']->forgetGuards();
        $this->post('/portal/login', ['email' => $client->email, 'password' => 'client-password']);
        $entry = Activity::where('description', 'Logged in to the portal')->sole();
        $this->assertSame(['client', $client->id], [$entry->actor_type, $entry->actor_id]);
    }

    public function test_activity_page_lists_and_filters_entries(): void
    {
        $client = Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        Activity::record('Created client', $client);
        Activity::record('Something unrelated');

        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/activity')->assertOk()->assertSee('Created client')->assertSee('Something unrelated');
        $this->get('/admin/activity?q=unrelated')->assertSee('Something unrelated')->assertDontSee('Created client');
        $this->get('/admin/activity?actor=staff')->assertSee('Nothing logged yet');
        $this->get("/admin/clients/{$client->id}")->assertSee('Recent activity')->assertSee('Created client')->assertDontSee('Something unrelated');
    }
}
