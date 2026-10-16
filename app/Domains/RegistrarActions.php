<?php

namespace App\Domains;

use App\Enums\DomainStatus;
use App\Jobs\RunRegistrarAction;
use App\Mail\ClientMailer;
use App\Models\Activity;
use App\Models\Domain;
use App\Notifications\StaffAlert;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The paid-for requests to a registrar: register, transfer and renew. Each
 * is queued, and its outcome saved on the domain for staff to see.
 */
class RegistrarActions
{
    public const ACTIONS = ['register', 'transfer', 'renew'];

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    private const DATE_FORMAT = 'M j, Y';

    public function __construct(private RegistrarRegistry $registrars, private ClientMailer $mailer) {}

    /** Queues an action; $years defaults to the domain's term. */
    public function queue(Domain $domain, string $action, ?int $years = null): void
    {
        $domain->forceFill(['registrar_status' => self::PENDING, 'registrar_action' => $action, 'registrar_error' => null])->save();

        RunRegistrarAction::dispatch($domain, $action, $years ?? $domain->years);
    }

    /** Runs an action now. Called by the queued job. */
    public function run(Domain $domain, string $action, int $years): void
    {
        try {
            [$registrar, $credentials] = $this->registrars->forDomain($domain);
            $result = match ($action) {
                'register' => $this->register($domain, $registrar, $credentials),
                'transfer' => $registrar->transfer($domain, $credentials),
                'renew' => $registrar->renew($domain, $years, $credentials),
            };
        } catch (Throwable $e) {
            if (! $e instanceof RegistrarError) {
                Log::error('Registrar action failed', ['domain' => $domain->id, 'action' => $action, 'exception' => $e]);
            }
            $result = RegistrarResult::failed($e->getMessage() ?: $e::class);
        }

        $result->ok ? $this->succeeded($domain, $action, $years, $result) : $this->failed($domain, $action, (string) $result->message);
    }

    /** @param  array<string, string>  $credentials */
    private function register(Domain $domain, Registrar $registrar, array $credentials): RegistrarResult
    {
        $missing = $domain->registrant()->missing();

        if ($missing !== null) {
            return RegistrarResult::failed("The registrant's {$missing} is missing. Add it to the client's details, then register the domain again.");
        }
        if (count($domain->nameservers ?? []) < 2) {
            return RegistrarResult::failed('The domain needs at least two nameservers. Set the default ones under Settings > Domains, or on the domain.');
        }

        return $registrar->register($domain, $credentials);
    }

    private function succeeded(Domain $domain, string $action, int $years, RegistrarResult $result): void
    {
        if ($result->data !== []) {
            $domain->rememberRegistrarValues($result->data);
        }

        $domain->forceFill(['registrar_status' => self::DONE, 'registrar_error' => null]);

        if ($action === 'transfer' && $result->inProgress) {
            $domain->forceFill(['status' => DomainStatus::PendingTransfer])->save();
            Activity::record("Started the transfer of {$domain->name} to {$domain->registrar}", $domain);

            return;
        }

        $expiry = $result->expiry ?? $this->expectedExpiry($domain, $action, $years);
        $domain->forceFill([
            'status' => DomainStatus::Active,
            'registration_date' => $domain->registration_date ?? today(),
            'expiry_date' => $expiry,
            'next_due_date' => $expiry,
        ])->save();

        $until = $expiry->format(self::DATE_FORMAT);
        Activity::record(match ($action) {
            'register' => "Registered {$domain->name} until {$until}",
            'transfer' => "Transferred {$domain->name} in, expiring {$until}",
            default => "Renewed {$domain->name} for {$years} year(s), until {$until}",
        }, $domain);

        $this->email($domain, match ($action) {
            'register' => 'domain_registered',
            'transfer' => 'domain_transferred',
            default => 'domain_renewed',
        });
    }

    /** Where the expiry date should be when the registrar didn't say. */
    private function expectedExpiry(Domain $domain, string $action, int $years): CarbonImmutable
    {
        $from = $action === 'renew' && $domain->expiry_date ? $domain->expiry_date : today();

        return CarbonImmutable::parse($from)->addYears($years);
    }

    private function failed(Domain $domain, string $action, string $error): void
    {
        $domain->forceFill(['registrar_status' => self::FAILED, 'registrar_error' => $error])->save();
        Activity::record(ucfirst($action)." of {$domain->name} failed: {$error}", $domain);

        StaffAlert::send(
            'manage-clients',
            "Domain {$action} failed: {$domain->name}",
            [
                "The {$action} request for {$domain->name} ({$domain->client->fullName()}) failed.",
                "Error: {$error}",
                'Fix the cause, then run it again from the domain page.',
            ],
            'Open the domain',
            route('admin.domains.show', $domain),
        );
    }

    /** Emails the client that the domain was registered, transferred in or renewed. */
    public function email(Domain $domain, string $template): void
    {
        $this->mailer->send($domain->client, $template, self::emailValues($domain));
    }

    /** @return array<string, string> */
    public static function emailValues(Domain $domain): array
    {
        return [
            'domain' => $domain->name,
            'expiry_date' => $domain->expiry_date?->translatedFormat(self::DATE_FORMAT) ?? '',
            'renewal_amount' => Money::format($domain->recurring_amount, $domain->client->currency),
            'renewal_years' => (string) $domain->years,
            'domain_url' => route('portal.domains.show', $domain),
        ];
    }
}
