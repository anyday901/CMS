<?php

namespace App\Domains;

use App\Enums\DomainStatus;
use App\Models\Activity;
use App\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * Brings domains in line with their registrar: expiry dates, renewals done
 * elsewhere, finished transfers, and domains that left. Run nightly, a
 * batch at a time, the longest unsynced first.
 */
class DomainSync
{
    public function __construct(private RegistrarRegistry $registrars, private RegistrarActions $actions, private DomainRenewals $renewals) {}

    /** @return array{synced: int, failed: int} */
    public function run(?int $limit = null): array
    {
        $configured = $this->registrars->all()->keys()->filter(fn (string $key) => $this->registrars->configured($key))->values();
        $counts = ['synced' => 0, 'failed' => 0];

        Domain::query()
            ->whereIn('status', DomainStatus::synced())
            ->whereIn('registrar', $configured)
            ->orderByRaw('synced_at is not null')->orderBy('synced_at')->orderBy('id')
            ->limit($limit ?? (int) config('domains.sync_batch'))
            ->with(['client', 'tld'])
            ->get()
            ->each(function (Domain $domain) use (&$counts) {
                $counts[$this->sync($domain) === null ? 'synced' : 'failed']++;
            });

        return $counts;
    }

    /** Syncs one domain; returns the error, or null when it worked. */
    public function sync(Domain $domain): ?string
    {
        try {
            [$registrar, $credentials] = $this->registrars->forDomain($domain);
            $info = $registrar->info($domain, $credentials);
        } catch (RegistrarError $e) {
            $domain->forceFill(['synced_at' => now(), 'sync_error' => mb_substr($e->getMessage(), 0, 255)])->save();

            return $e->getMessage();
        }

        $this->apply($domain, $info);

        return null;
    }

    private function apply(Domain $domain, DomainInfo $info): void
    {
        $was = $domain->status;
        $domain->forceFill(['synced_at' => now(), 'sync_error' => null] + array_filter([
            'nameservers' => $info->nameservers ?: null,
            'locked' => $info->locked,
            'id_protection' => $info->idProtection,
        ], fn ($value) => $value !== null));

        if ($was === DomainStatus::PendingTransfer) {
            $this->transferProgress($domain, $info);

            return;
        }

        match ($info->status) {
            DomainInfo::GONE => $domain->status = DomainStatus::TransferredAway,
            DomainInfo::EXPIRED => $domain->status = DomainStatus::Expired,
            default => $this->followExpiry($domain, $info->expiry),
        };
        $domain->save();

        if ($domain->status !== $was) {
            Activity::record("Sync: {$domain->name} is now ".mb_strtolower($domain->status->label()), $domain);
        }
    }

    /** A transfer finishes when the registrar lists the domain as active in our account. */
    private function transferProgress(Domain $domain, DomainInfo $info): void
    {
        if ($info->status !== DomainInfo::ACTIVE) {
            $domain->save();

            return;
        }

        $expiry = $info->expiry ?? CarbonImmutable::today()->addYear();
        $domain->forceFill([
            'status' => DomainStatus::Active,
            'registration_date' => $domain->registration_date ?? today(),
            'expiry_date' => $expiry,
            'next_due_date' => $expiry,
        ])->save();
        Activity::record("Sync: the transfer of {$domain->name} finished, expiring {$expiry->format('M j, Y')}", $domain);
        $this->actions->email($domain, 'domain_transferred');
    }

    /**
     * Takes the registrar's expiry date. The renewal due date follows it too,
     * unless a renewal invoice for the current date is open, so that invoice
     * still counts.
     */
    private function followExpiry(Domain $domain, ?CarbonImmutable $expiry): void
    {
        if ($expiry === null) {
            return;
        }

        $domain->expiry_date = $expiry;
        $domain->status = $expiry->isPast() && ! $expiry->isToday() ? DomainStatus::Expired : DomainStatus::Active;

        if (! $domain->next_due_date?->equalTo($expiry) && $this->renewals->openRenewal($domain) === null && ! DomainRenewals::renewalPaid($domain)) {
            $domain->next_due_date = $expiry;
        }
    }
}
