# Event hooks

When something happens in CMS, such as an invoice being paid or a ticket opened, it fires an **event** through the hook system. Add-ons and plugins listen to events in code; outgoing webhooks, chat channels and SMS are subscribers that see every event and send on the ones staff picked.

The code is in `app/Hooks`:

| Class | What it is |
|---|---|
| `Hooks` | The hook system itself, a singleton: `listen()`, `define()`, `fire()` and `events()` |
| `HookEvent` | One thing that happened: its key, data, a sentence for people, and a link |
| `HookCatalog` | The core events with their labels, and the wiring that turns the app's Laravel events into hook events |
| `Payload` | The data sent with each core event: plain arrays of a record's main fields |
| `HookSubscriber` | The interface for something that sees every event, such as outgoing webhooks |

## Listening to events

Get the singleton from the container and listen with a key, a list of keys, or `Hooks::ANY` for every event:

```php
use App\Hooks\HookEvent;
use App\Hooks\Hooks;

$hooks = app(Hooks::class);

$hooks->listen('invoice.paid', function (HookEvent $event) {
    Board::add($event->data['total'], $event->data['currency']);
});

$hooks->listen(['service.suspended', 'service.terminated'], fn (HookEvent $event) => ...);

$hooks->listen(Hooks::ANY, fn (HookEvent $event) => Log::info($event->summary));
```

In an add-on, listen in `boot()`, which runs on every request while the add-on is enabled. Listen in `bootInstalled()` instead for hooks that keep the add-on's own data right even while it is disabled, such as `client.merged` (see [Add-ons](../addons.md)). Outside an add-on, listen from a service provider's `boot()`.

How listeners are called:

- **After the change is saved.** Core events are fired once the database change that caused them is saved. `client.merged` is fired inside the merge's transaction on purpose, before the merged account is deleted, so an add-on can move its own rows as part of it; `staff.alert` can also come from inside a transaction, such as recording a dispute. The webhook, chat and SMS jobs always wait for the transaction to commit.
- **In order**: listeners for the event's key first, then `Hooks::ANY` listeners, then the subscribers in `config/integrations.php`.
- **Safely.** A listener that throws is reported to the log and skipped, so a broken add-on can't stop a payment being recorded or other listeners running.
- **In the same request.** Keep listeners quick, and dispatch a queued job for anything slow or that calls another system.

## `HookEvent`

```php
final class HookEvent
{
    public readonly string $id;                   // a UUID, new for each event
    public readonly CarbonImmutable $occurredAt;

    public function __construct(
        public readonly string $key,              // such as "invoice.paid"
        public readonly array $data,              // the record's main fields
        public readonly string $summary,          // "Invoice #1042 paid by Jane Doe: $15.00"
        public readonly ?string $url = null,      // the record's page in the admin area
        public readonly ?int $clientId = null,    // the client it concerns, if any
    ) {}

    /** The body webhooks receive. */
    public function toArray(): array;
}
```

`toArray()` gives `id`, `event` (the key), `occurred_at` (ISO 8601), `summary`, `url` and `data`, which is exactly what a [webhook delivery](../webhooks.md#webhook-requests) contains.

In `data`, money is an integer in minor units (cents) with its currency, dates are `YYYY-MM-DD`, and times are ISO 8601.

## Built-in events

`HookCatalog::events()` lists the core events with the labels staff see when they pick events for a webhook, chat channel or SMS. `$hooks->events()` returns those plus every event add-ons have defined.

| Key | When | `data` | `clientId` | `url` |
|---|---|---|---|---|
| `client.created` | A client signs up at checkout, or staff or the API add one | [client](#client) | the client | the client |
| `client.merged` | Staff merge one client into another | `from_client_id`, `into_client_id` | the client kept | the client kept |
| `order.placed` | A client checks out in the store | [order](#order) | the client | the order |
| `invoice.created` | An invoice is issued | [invoice](#invoice) | the client | the invoice |
| `invoice.paid` | An invoice is paid in full | [invoice](#invoice) | the client | the invoice |
| `service.activated` | A service is activated | [service](#service) | the client | the service |
| `service.changed` | A service is upgraded or downgraded | [service](#service), on its new product | the client | the service |
| `service.suspended` | A service is suspended | [service](#service) | the client | the service |
| `service.unsuspended` | A service is unsuspended | [service](#service) | the client | the service |
| `service.terminated` | A service is terminated | [service](#service) | the client | the service |
| `ticket.opened` | A client or guest opens a ticket | [ticket](#ticket) | the client, or null for a guest | the ticket |
| `ticket.replied` | A client or guest replies to a ticket | [ticket](#ticket) | the client, or null | the ticket |
| `ticket.answered` | Staff reply to a ticket (not internal notes) | [ticket](#ticket) | the client, or null | the ticket |
| `affiliate.signup` | A client becomes an affiliate | `affiliate` | the affiliate's client | the affiliate |
| `affiliate.commission` | An affiliate earns a commission on a paid invoice | `affiliate`, `commission` | the affiliate's client | the affiliate |
| `affiliate.payout_requested` | An affiliate asks for a payout | `affiliate`, `payout` | the affiliate's client | the payouts page |
| `staff.alert` | Something needs staff: a failed provisioning action, a payment dispute, a manual task, a server down | `subject`, `lines` (a list of sentences), `url` | none | the thing to look at |

`webhook.test` isn't in the catalogue: it is sent only to the one webhook whose **Send a test** button was pressed, with `webhook_id` and `name`.

`staff.alert` is fired by `App\Notifications\StaffAlert::send()`, which also emails the staff whose role has the given ability. Ticket alerts to staff use the ticket events instead.

### The data of each record

These come from `App\Hooks\Payload` and match what the [REST API](../api.md) returns.

#### client

`id`, `first_name`, `last_name`, `company`, `email`, `phone`, `country`, `currency`, `status` (`active`, `inactive` or `closed`), `client_group_id`, `credit_balance` (cents), `marketing_emails` (true or false), `created_at`.

#### order

`id`, `client_id`, `invoice_id`, `status`, `promotion_code`, `service_ids` (a list), `created_at`.

#### invoice

`id`, `number`, `client_id`, `status`, `currency`, `subtotal`, `tax`, `total`, `balance` (all in cents), `issue_date`, `due_date`, `paid_at`.

#### service

`id`, `client_id`, `product_id`, `product_name`, `description` (the product name with the service's label), `label` (such as its domain), `status`, `billing_cycle`, `recurring_amount` (cents), `registration_date`, `next_due_date`.

#### ticket

`id`, `client_id` (null for a guest), `guest_email`, `department_id`, `department` (its name), `subject`, `status`, `priority`, `assigned_to` (a staff user id, or null), `last_reply_at`.

#### affiliate events

- `affiliate`: `id`, `client_id`, `code`, `status`, `link` (the referral link), `visits`.
- `commission` (on `affiliate.commission`): `id`, `invoice_id`, `client_id` (the referred client), `amount` (cents), `currency`, `status`, `available_at` (when it clears).
- `payout` (on `affiliate.payout_requested`): `id`, `amount` (cents), `currency`, `status`, `created_at`.

### Add-on events

The bundled project management add-on defines `project.created`, `project.completed`, `project.task_completed` and `project.invoiced`, while it is enabled. Each has `project` (`id`, `title`, `client_id`, `user_id`, `status`, `due_on`, `hourly_rate`, `currency`, `completed_at`, `created_at`); `project.task_completed` adds `task` (`id`, `title`, `user_id`, `due_on`, `completed_at`), and `project.invoiced` adds `invoice_id`, `invoice_total` and `time_entries`. See `app/Addons/ProjectManagement/ProjectHooks.php`.

## Defining and firing your own events

An add-on defines its events in `boot()`, so staff can pick them for webhooks, chat channels and SMS, and fires them with a `HookEvent`:

```php
public function boot(): void
{
    app(Hooks::class)->define('status.shown', 'Status board shown');
}
```

```php
app(Hooks::class)->fire(new HookEvent(
    'status.shown',
    ['board' => ['id' => $board->id, 'title' => $board->title]],
    "Status board {$board->title} shown",
    route('admin.status.index'),
    $board->client_id,
));
```

- **Prefix keys with your add-on's key** (`status.`), so they can't clash with the core's or another add-on's.
- **Give `data` plain values**: ids, strings, numbers, booleans and ISO 8601 dates, with money in cents and its currency. It is stored with each webhook delivery and sent as JSON.
- **Write the summary as a sentence for people.** Chat channels and staff texts send just the summary and the link.
- **Pass the client id** when the event concerns a client, so client-facing subscribers can find them.
- **Fire after saving.** If you fire inside a database transaction, webhook, chat and SMS jobs still wait for the commit, but listeners run at once.

Keep `ProjectHooks` in mind as the pattern: one class with the keys as constants, an `events()` list for `define()`, and one method per event that builds its data.

## Subscribers: webhooks, chat and SMS

A subscriber sees every event and decides what to do with it. The built-in ones are listed in `config/integrations.php`:

```php
'subscribers' => [
    WebhookDispatcher::class,   // App\Integrations\Webhooks
    ChatNotifier::class,        // App\Integrations\Chat
    SmsNotifier::class,         // App\Integrations\Sms
],
```

Each implements `App\Hooks\HookSubscriber`:

```php
interface HookSubscriber
{
    public function handle(HookEvent $event): void;
}
```

- **`WebhookDispatcher`** finds the active webhooks subscribed to the event's key, or to every event (`Hooks::ANY`), saves a delivery with `$event->toArray()` as the body, and queues `App\Jobs\DeliverWebhook`. Each delivery is signed and tried up to five times. See [Webhooks and notifications](../webhooks.md).
- **`ChatNotifier`** finds the chat channels (Slack, Discord or Teams) subscribed to the event, or to every event, and queues `App\Jobs\SendChatMessage` with the summary and link.
- **`SmsNotifier`** texts the staff numbers about the events staff picked under **Settings > SMS**, with the summary. It texts the client (found by `clientId`) about `invoice.created`, `invoice.paid`, `service.activated`, `service.suspended` and `ticket.answered`, when picked, if the client agreed to texts and has a phone number. Its client messages are written for those events only (`SmsNotifier::CLIENT_EVENTS`), so add-on events reach staff by SMS, not clients. See [SMS providers](sms.md).

Because these are subscribers, an event your add-on defines can be sent by webhook, chat or SMS without more code. The webhook, chat channel and SMS forms list every key in `$hooks->events()`.

To add a subscriber of your own, such as one that forwards events to a message bus, implement `HookSubscriber` and add the class to `subscribers`. It is resolved through the container for each event, and called after the listeners; like them, an exception is reported and skipped. Queue anything slow.

## Testing

Listen in the test and assert what arrived:

```php
$events = [];
app(Hooks::class)->listen('invoice.paid', function (HookEvent $event) use (&$events) {
    $events[] = $event;
});

// ... pay an invoice

$this->assertCount(1, $events);
$this->assertSame(1500, $events[0]->data['total']);
```

`tests/Feature/Integrations/HooksAndWebhooksTest.php` covers listeners on core events (including one that throws), events defined by plugins, ticket events and webhook deliveries.
