<?php

namespace App\Integrations\Sms;

use App\Hooks\HookEvent;
use App\Hooks\HookSubscriber;
use App\Models\Client;
use App\Support\Money;

/**
 * Texts staff numbers about the events chosen for staff, and clients who
 * agreed to account texts about the events chosen for clients.
 */
class SmsNotifier implements HookSubscriber
{
    /** Events clients can be texted about, with what they're told. */
    public const CLIENT_EVENTS = [
        'invoice.created' => 'New invoice',
        'invoice.paid' => 'Payment received',
        'service.activated' => 'Service activated',
        'service.suspended' => 'Service suspended',
        'ticket.answered' => 'Support replied to a ticket',
    ];

    public function __construct(private Sms $sms) {}

    public function handle(HookEvent $event): void
    {
        if (! $this->sms->configured()) {
            return;
        }

        if (in_array($event->key, (array) config('sms.staff_events'), true)) {
            foreach ((array) config('sms.staff_numbers') as $number) {
                $this->sms->queue($number, config('app.name').': '.$event->summary, null, $event->key);
            }
        }

        if ($event->clientId !== null && in_array($event->key, (array) config('sms.client_events'), true)) {
            $client = Client::find($event->clientId);
            $message = $client ? $this->clientMessage($event) : null;

            if ($message !== null && $client->sms_notifications && filled($client->phone)) {
                $this->sms->queue($client->phone, $message, $client->id, $event->key);
            }
        }
    }

    private function clientMessage(HookEvent $event): ?string
    {
        $company = (string) (config('billing.company.name') ?: config('app.name'));
        $d = $event->data;
        $money = fn () => Money::format((int) ($d['total'] ?? 0), (string) ($d['currency'] ?? ''));

        return match ($event->key) {
            'invoice.created' => "{$company}: invoice #{$d['number']} for {$money()} is due ".($d['due_date'] ?? '').'. Pay it at '.route('portal.invoices.show', $d['id']),
            'invoice.paid' => "{$company}: thank you, we received payment for invoice #{$d['number']} ({$money()}).",
            'service.activated' => "{$company}: your {$d['description']} is now active.",
            'service.suspended' => "{$company}: your {$d['description']} has been suspended. Log in to ".route('portal.dashboard').' for details.',
            'ticket.answered' => "{$company}: we replied to your ticket #{$d['id']} ({$d['subject']}).",
            default => null,
        };
    }
}
