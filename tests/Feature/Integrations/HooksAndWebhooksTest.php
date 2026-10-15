<?php

namespace Tests\Feature\Integrations;

use App\Billing\PaymentRecorder;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Jobs\DeliverWebhook;
use App\Models\Client;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\ListenerFailed;
use Tests\TestCase;

class HooksAndWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://example.com/hooks';

    private const WEBHOOKS_URL = '/admin/settings/webhooks';

    private function paidInvoice(?Client $client = null): Invoice
    {
        $client ??= Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1500]);
        $invoice->recalculate();
        app(PaymentRecorder::class)->record($invoice, 1500, 'manual');

        return $invoice->refresh();
    }

    public function test_plugins_listen_to_core_events_and_a_broken_listener_stops_nothing(): void
    {
        $seen = [];
        $hooks = app(Hooks::class);
        $hooks->listen('invoice.paid', fn () => throw new ListenerFailed('Broken plugin'));
        $hooks->listen('invoice.paid', function (HookEvent $event) use (&$seen) {
            $seen[] = $event;
        });
        $hooks->listen(Hooks::ANY, function (HookEvent $event) use (&$seen) {
            $seen[] = $event->key;
        });

        $invoice = $this->paidInvoice();

        $this->assertSame('paid', $invoice->status->value);
        $event = collect($seen)->first(fn ($e) => $e instanceof HookEvent);
        $this->assertSame('invoice.paid', $event->key);
        $this->assertSame($invoice->id, $event->data['id']);
        $this->assertSame(1500, $event->data['total']);
        $this->assertSame($invoice->client_id, $event->clientId);
        $this->assertStringContainsString("Invoice #{$invoice->number} paid by", $event->summary);
        $this->assertContains('invoice.paid', $seen);
    }

    public function test_plugins_can_define_their_own_events(): void
    {
        $hooks = app(Hooks::class);
        $hooks->define('example.thing', 'Example thing');

        $this->assertTrue($hooks->has('example.thing'));
        $this->assertSame('Example thing', $hooks->events()['example.thing']);
    }

    public function test_ticket_events_fire_from_the_ticket_desk(): void
    {
        $keys = [];
        app(Hooks::class)->listen(Hooks::ANY, function (HookEvent $event) use (&$keys) {
            $keys[] = $event->key;
        });

        $desk = app(TicketDesk::class);
        $ticket = $desk->open(Client::factory()->create(), Department::create(['name' => 'Support']), 'Help', 'It broke');
        $desk->clientReply($ticket, 'Still broken');
        $desk->staffReply($ticket, User::factory()->create(), 'Fixed now');
        $desk->staffReply($ticket, User::factory()->create(), 'Note to self', internal: true);

        $this->assertSame(['ticket.opened', 'ticket.replied', 'ticket.answered'], $keys);
    }

    public function test_subscribed_webhooks_get_a_signed_post(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true])]);
        $webhook = Webhook::create(['name' => 'Accounts', 'url' => self::URL, 'secret' => 'whsec_test', 'events' => ['invoice.paid'], 'active' => true]);
        Webhook::create(['name' => 'Tickets only', 'url' => 'https://example.com/other', 'secret' => 's', 'events' => ['ticket.opened'], 'active' => true]);
        Webhook::create(['name' => 'Off', 'url' => 'https://example.com/off', 'secret' => 's', 'events' => ['*'], 'active' => false]);

        $invoice = $this->paidInvoice();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($invoice) {
            $body = json_decode($request->body(), true);

            return $request->url() === self::URL
                && $request->header('X-Webhook-Event')[0] === 'invoice.paid'
                && $request->header('X-Webhook-Signature')[0] === DeliverWebhook::signature($request->body(), 'whsec_test')
                && $body['event'] === 'invoice.paid'
                && $body['data']['id'] === $invoice->id
                && $request->header('X-Webhook-Delivery')[0] === $body['id'];
        });
        $delivery = $webhook->deliveries()->sole();
        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->status);
        $this->assertSame(200, $delivery->response_status);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_webhook_for_every_event_gets_them_all_and_failures_are_logged(): void
    {
        Http::fake([self::URL => Http::response('Nope', 500)]);
        $webhook = Webhook::create(['name' => 'All', 'url' => self::URL, 'secret' => 's', 'events' => ['*'], 'active' => true]);

        $this->paidInvoice();

        $delivery = $webhook->deliveries()->where('event', 'invoice.paid')->sole();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertSame(500, $delivery->response_status);
        $this->assertSame('Nope', $delivery->response_body);
        $this->assertSame('The URL answered HTTP 500.', $delivery->error);
    }

    public function test_staff_manage_webhooks_send_tests_and_redeliver(): void
    {
        Http::fake([self::URL => Http::sequence()->push('down', 503)->push('ok', 200)->push('ok', 200)]);
        $this->actingAs(User::factory()->create());

        $this->get(self::WEBHOOKS_URL)->assertOk()->assertSee('No webhooks yet.');
        $this->post(self::WEBHOOKS_URL, ['name' => 'Bad', 'url' => 'ftp://example.com', 'events' => ['nope']])
            ->assertSessionHasErrors(['url', 'events.0']);

        $this->post(self::WEBHOOKS_URL, ['name' => 'Accounts', 'url' => self::URL, 'events' => ['invoice.paid', '*'], 'active' => '1'])->assertRedirect();
        $webhook = Webhook::sole();
        $this->assertSame(['*'], $webhook->events);
        $this->assertStringStartsWith('whsec_', $webhook->secret);
        $this->get("/admin/settings/webhooks/{$webhook->id}/edit")->assertOk()->assertSee($webhook->secret)->assertSee('Nothing sent yet.');

        $this->post("/admin/settings/webhooks/{$webhook->id}/test")->assertSessionHas('status');
        $delivery = $webhook->deliveries()->sole();
        $this->assertSame('webhook.test', $delivery->event);
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);

        $this->post("/admin/settings/webhook-deliveries/{$delivery->id}/redeliver")->assertSessionHas('status');
        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);

        $old = $webhook->secret;
        $this->post("/admin/settings/webhooks/{$webhook->id}/secret");
        $this->assertNotSame($old, $webhook->fresh()->secret);

        $this->put("/admin/settings/webhooks/{$webhook->id}", ['name' => 'Renamed', 'url' => self::URL, 'events' => ['ticket.opened']])->assertRedirect();
        $this->assertSame(['ticket.opened'], $webhook->fresh()->events);
        $this->assertFalse($webhook->fresh()->active);

        $this->delete("/admin/settings/webhooks/{$webhook->id}")->assertRedirect(self::WEBHOOKS_URL);
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_only_settings_staff_manage_webhooks(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']));

        $this->get(self::WEBHOOKS_URL)->assertForbidden();
        $this->get('/admin/settings/chat')->assertForbidden();
        $this->get('/admin/settings/sms')->assertForbidden();
        $this->get('/admin/settings/addons')->assertForbidden();
    }
}
