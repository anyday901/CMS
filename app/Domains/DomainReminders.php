<?php

namespace App\Domains;

use App\Enums\DomainStatus;
use App\Mail\ClientMailer;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\DomainReminder;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Renewal reminders and expiry notices, run nightly. A domain gets a
 * reminder when it is within each number of days in domains.reminder_days
 * of expiring, once per number and expiry date; a missed night sends the
 * latest one only. Domains whose renewal is already paid get none.
 */
class DomainReminders
{
    public function __construct(private ClientMailer $mailer, private DomainRenewals $renewals) {}

    /** @return array{reminded: int, expired: int} */
    public function run(CarbonInterface $today): array
    {
        return ['expired' => $this->expire($today), 'reminded' => $this->remind($today)];
    }

    private function remind(CarbonInterface $today): int
    {
        $days = collect(config('domains.reminder_days', []))->map(fn ($d) => (int) $d)->filter(fn (int $d) => $d > 0)->sort()->values();

        if ($days->isEmpty()) {
            return 0;
        }

        $sent = 0;
        Domain::where('status', DomainStatus::Active)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($days->last()))
            ->with('client')
            ->chunkById(200, function ($domains) use ($today, $days, &$sent) {
                foreach ($domains as $domain) {
                    $left = (int) $today->diffInDays($domain->expiry_date);
                    $threshold = $days->first(fn (int $d) => $left <= $d);

                    if ($threshold !== null && ! DomainRenewals::renewalPaid($domain) && $this->once($domain, $threshold)) {
                        $this->mailer->send($domain->client, 'domain_renewal_reminder', RegistrarActions::emailValues($domain) + [
                            'days_left' => (string) $left,
                            'renewal_note' => $this->renewalNote($domain),
                        ]);
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /** Marks active domains past their expiry date as expired, with a notice to the client. */
    private function expire(CarbonInterface $today): int
    {
        $expired = 0;
        Domain::where('status', DomainStatus::Active)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->with('client')
            ->chunkById(200, function ($domains) use (&$expired) {
                foreach ($domains as $domain) {
                    if (DomainRenewals::renewalPaid($domain)) {
                        continue; // Paid, and waiting on the registrar: not the client's to fix.
                    }
                    $domain->forceFill(['status' => DomainStatus::Expired])->save();
                    Activity::record("{$domain->name} expired", $domain);
                    if ($this->once($domain, 0)) {
                        $this->mailer->send($domain->client, 'domain_expired', RegistrarActions::emailValues($domain));
                    }
                    $expired++;
                }
            });

        return $expired;
    }

    /** Records the reminder; false when it was already sent. */
    private function once(Domain $domain, int $days): bool
    {
        try {
            DomainReminder::create(['domain_id' => $domain->id, 'expiry_date' => $domain->expiry_date, 'days' => $days]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function renewalNote(Domain $domain): string
    {
        if (! $domain->auto_renew) {
            return __("Auto-renew is off, so it won't renew unless you renew it in the portal.");
        }

        return $this->renewals->openRenewal($domain)
            ? __('It renews automatically once its renewal invoice is paid.')
            : __("It renews automatically: we'll send the renewal invoice before it expires.");
    }
}
