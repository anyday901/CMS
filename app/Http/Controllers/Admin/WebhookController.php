<?php

namespace App\Http\Controllers\Admin;

use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Http\Controllers\Controller;
use App\Integrations\Webhooks\WebhookDispatcher;
use App\Jobs\DeliverWebhook;
use App\Models\Activity;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Webhooks: URLs that get a signed POST when events happen. */
class WebhookController extends Controller
{
    /** The event sent by "Send a test". */
    public const TEST_EVENT = 'webhook.test';

    public function __construct(private Hooks $hooks) {}

    public function index(): View
    {
        return view('admin.webhooks.index', [
            'webhooks' => Webhook::withCount(['deliveries as failed_count' => fn ($q) => $q->where('status', WebhookDelivery::FAILED)])->orderBy('name')->get(),
            'webhook' => new Webhook(['events' => [], 'active' => true]),
            'events' => $this->hooks->events(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $webhook = Webhook::create($this->validated($request) + ['secret' => self::newSecret()]);
        Activity::record("Added webhook {$webhook->name}", $webhook);

        return redirect()->route('admin.webhooks.edit', $webhook)->with('status', 'Webhook added. Copy its signing secret below to check deliveries are really from us.');
    }

    public function edit(Webhook $webhook): View
    {
        return view('admin.webhooks.edit', [
            'webhook' => $webhook,
            'events' => $this->hooks->events(),
            'deliveries' => $webhook->deliveries()->latest('id')->limit(50)->get(),
        ]);
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $webhook->update($this->validated($request));
        Activity::record("Updated webhook {$webhook->name}", $webhook);

        return redirect()->route('admin.webhooks.edit', $webhook)->with('status', 'Webhook saved.');
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $webhook->delete();
        Activity::record("Deleted webhook {$webhook->name}");

        return redirect()->route('admin.webhooks.index')->with('status', 'Webhook deleted.');
    }

    /** A new signing secret; the old one stops working at once. */
    public function secret(Webhook $webhook): RedirectResponse
    {
        $webhook->update(['secret' => self::newSecret()]);
        Activity::record("Made a new signing secret for webhook {$webhook->name}", $webhook);

        return back()->with('status', 'New signing secret made. Update it where the webhook is received.');
    }

    /** Sends a ping event, whatever the webhook subscribes to. */
    public function test(Webhook $webhook, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->queue($webhook, new HookEvent(self::TEST_EVENT, ['webhook_id' => $webhook->id, 'name' => $webhook->name], 'A test from '.config('app.name')));

        return back()->with('status', 'Test sent. Its result is in the deliveries below.');
    }

    /** Sends a delivery again, as it was. */
    public function redeliver(WebhookDelivery $delivery): RedirectResponse
    {
        $delivery->update(['status' => WebhookDelivery::PENDING, 'error' => null]);
        DeliverWebhook::dispatch($delivery->id);

        return back()->with('status', 'Sent again.');
    }

    private static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in([Hooks::ANY, ...array_keys($this->hooks->events())])],
            'active' => ['boolean'],
        ], ['events.required' => 'Pick at least one event.']);

        // Every event covers the rest.
        $data['events'] = in_array(Hooks::ANY, $data['events'], true) ? [Hooks::ANY] : array_values(array_unique($data['events']));
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
