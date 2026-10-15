<?php

namespace App\Affiliates;

use App\Events\InvoicePaid;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\Event;

/**
 * Connects the affiliate program to the rest of the app: a paid invoice
 * earns its commission and a refund takes it back. Also fires the
 * affiliate hook events, which webhooks and chat channels can subscribe to.
 */
class AffiliateHooks
{
    public const SIGNUP = 'affiliate.signup';

    public const COMMISSION = 'affiliate.commission';

    public const PAYOUT_REQUESTED = 'affiliate.payout_requested';

    public static function wire(): void
    {
        Event::listen(InvoicePaid::class, fn (InvoicePaid $e) => app(Commissions::class)->invoicePaid($e->invoice));

        // Every refund is a negative transaction linked to the payment, however it was made.
        Event::listen('eloquent.created: '.Transaction::class, function (Transaction $transaction) {
            if ($transaction->refund_of_id !== null && $transaction->invoice_id !== null) {
                app(Commissions::class)->refunded($transaction);
            }
        });
    }

    public static function signedUp(Affiliate $affiliate): void
    {
        $client = $affiliate->client;
        self::fire(new HookEvent(self::SIGNUP, self::affiliate($affiliate), "New affiliate: {$client->fullName()} ({$client->email})", route('admin.affiliates.show', $affiliate), $client->id));
    }

    public static function commissionEarned(AffiliateCommission $commission): void
    {
        $affiliate = $commission->affiliate;
        self::fire(new HookEvent(self::COMMISSION, self::affiliate($affiliate) + ['commission' => self::commission($commission)],
            "Affiliate {$affiliate->client->fullName()} earned ".Money::format($commission->amount, $commission->currency).($commission->invoice ? " on invoice #{$commission->invoice->number}" : ''),
            route('admin.affiliates.show', $affiliate), $affiliate->client_id));
    }

    public static function payoutRequested(AffiliatePayout $payout): void
    {
        $affiliate = $payout->affiliate;
        self::fire(new HookEvent(self::PAYOUT_REQUESTED, self::affiliate($affiliate) + ['payout' => [
            'id' => $payout->id,
            'amount' => $payout->amount,
            'currency' => $payout->currency,
            'status' => $payout->status,
            'created_at' => $payout->created_at?->toIso8601String(),
        ]], "Affiliate {$affiliate->client->fullName()} asked for a payout of ".Money::format($payout->amount, $payout->currency),
            route('admin.affiliates.payouts'), $affiliate->client_id));
    }

    private static function fire(HookEvent $event): void
    {
        app(Hooks::class)->fire($event);
    }

    /** @return array<string, mixed> */
    private static function affiliate(Affiliate $affiliate): array
    {
        return [
            'affiliate' => [
                'id' => $affiliate->id,
                'client_id' => $affiliate->client_id,
                'code' => $affiliate->code,
                'status' => $affiliate->status,
                'link' => $affiliate->link(),
                'visits' => (int) $affiliate->visits,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function commission(AffiliateCommission $commission): array
    {
        return [
            'id' => $commission->id,
            'invoice_id' => $commission->invoice_id,
            'client_id' => $commission->referral?->client_id,
            'amount' => $commission->amount,
            'currency' => $commission->currency,
            'status' => $commission->status,
            'available_at' => $commission->available_at?->toIso8601String(),
        ];
    }
}
