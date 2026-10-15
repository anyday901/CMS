<?php

namespace App\Hooks;

use App\Events\ClientCreated;
use App\Events\InvoiceIssued;
use App\Events\InvoicePaid;
use App\Events\OrderPlaced;
use App\Events\ServiceActivated;
use App\Events\ServiceChanged;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\ServiceUnsuspended;
use App\Events\TicketOpened;
use App\Events\TicketReplied;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Support\Facades\Event;

/** The core events and how each becomes a HookEvent. */
class HookCatalog
{
    /** Staff alerts (a failed provisioning action, a dispute); fired by App\Notifications\StaffAlert. */
    public const STAFF_ALERT = 'staff.alert';

    /**
     * Fired by App\Clients\ClientMerger inside its transaction, before the
     * merged account is deleted, with from_client_id and into_client_id.
     * Add-ons with a client_id in their own tables move those rows here.
     */
    public const CLIENT_MERGED = 'client.merged';

    /** @return array<string, string> */
    public static function events(): array
    {
        return [
            'client.created' => 'New client',
            self::CLIENT_MERGED => 'Clients merged',
            'order.placed' => 'New order',
            'invoice.created' => 'Invoice issued',
            'invoice.paid' => 'Invoice paid',
            'service.activated' => 'Service activated',
            'service.changed' => 'Service upgraded or downgraded',
            'service.suspended' => 'Service suspended',
            'service.unsuspended' => 'Service unsuspended',
            'service.terminated' => 'Service terminated',
            'ticket.opened' => 'New ticket',
            'ticket.replied' => 'Client replied to a ticket',
            self::STAFF_ALERT => 'Staff alert (failed provisioning, disputes, tasks)',
        ];
    }

    /** Fires the hook for each core Laravel event. */
    public static function wire(): void
    {
        $fire = fn (HookEvent $event) => app(Hooks::class)->fire($event);

        Event::listen(ClientCreated::class, fn (ClientCreated $e) => $fire(new HookEvent(
            'client.created',
            Payload::client($e->client),
            "New client: {$e->client->fullName()} ({$e->client->email})",
            route('admin.clients.show', $e->client),
            $e->client->id,
        )));

        Event::listen(OrderPlaced::class, function (OrderPlaced $e) use ($fire) {
            $order = $e->order->loadMissing(['client', 'invoice']);
            $total = $order->invoice ? ': '.Money::format($order->invoice->total, $order->invoice->currency) : '';
            $fire(new HookEvent('order.placed', Payload::order($order), "New order #{$order->id} from {$order->client->fullName()}{$total}", route('admin.orders.show', $order), $order->client_id));
        });

        Event::listen(InvoiceIssued::class, function (InvoiceIssued $e) use ($fire) {
            $invoice = $e->invoice->loadMissing('client');
            $fire(new HookEvent('invoice.created', Payload::invoice($invoice), "Invoice #{$invoice->number} issued to {$invoice->client->fullName()}: ".Money::format($invoice->total, $invoice->currency), route('admin.invoices.show', $invoice), $invoice->client_id));
        });

        Event::listen(InvoicePaid::class, function (InvoicePaid $e) use ($fire) {
            $invoice = $e->invoice->loadMissing('client');
            $fire(new HookEvent('invoice.paid', Payload::invoice($invoice), "Invoice #{$invoice->number} paid by {$invoice->client->fullName()}: ".Money::format($invoice->total, $invoice->currency), route('admin.invoices.show', $invoice), $invoice->client_id));
        });

        foreach ([
            ServiceActivated::class => ['service.activated', 'activated'],
            ServiceChanged::class => ['service.changed', 'moved to another product'],
            ServiceSuspended::class => ['service.suspended', 'suspended'],
            ServiceUnsuspended::class => ['service.unsuspended', 'unsuspended'],
            ServiceTerminated::class => ['service.terminated', 'terminated'],
        ] as $class => [$key, $verb]) {
            Event::listen($class, function ($e) use ($fire, $key, $verb) {
                /** @var Service $service */
                $service = $e->service->loadMissing(['client', 'product']);
                $fire(new HookEvent($key, Payload::service($service), "{$service->description()} for {$service->client->fullName()} {$verb}", route('admin.services.show', $service), $service->client_id));
            });
        }

        Event::listen(TicketOpened::class, function (TicketOpened $e) use ($fire) {
            $ticket = $e->ticket->loadMissing(['client', 'department']);
            $fire(new HookEvent('ticket.opened', Payload::ticket($ticket), "New ticket #{$ticket->id} from {$ticket->requesterName()} in {$ticket->department->name}: {$ticket->subject}", route('admin.tickets.show', $ticket), $ticket->client_id));
        });

        Event::listen(TicketReplied::class, function (TicketReplied $e) use ($fire) {
            $ticket = $e->ticket->loadMissing(['client', 'department']);
            $fire(new HookEvent('ticket.replied', Payload::ticket($ticket), "{$ticket->requesterName()} replied to ticket #{$ticket->id}: {$ticket->subject}", route('admin.tickets.show', $ticket), $ticket->client_id));
        });
    }
}
