<?php

namespace App\Domains\Registrars;

use App\Domains\DnsRecord;
use App\Domains\DomainContact;
use App\Domains\DomainInfo;
use App\Domains\ManagesDns;
use App\Domains\Registrar;
use App\Domains\RegistrarError;
use App\Domains\RegistrarResult;
use App\Enums\DomainStatus;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ResellerClub's HTTP API (LogicBoxes, also used by other brands on the
 * same platform). Each client becomes a customer in the reseller account,
 * and each domain gets its own contact.
 */
class ResellerClub implements ManagesDns, Registrar
{
    /** The add and delete calls for each record type in its DNS service. */
    private const DNS_CALLS = ['A' => 'ipv4', 'AAAA' => 'ipv6', 'CNAME' => 'cname', 'MX' => 'mx', 'TXT' => 'txt'];

    private const ORDER_ID = 'order-id';

    private const DOMAIN_NAME = 'domain-name';

    public function key(): string
    {
        return 'resellerclub';
    }

    public function label(): string
    {
        return 'ResellerClub';
    }

    public function configFields(): array
    {
        return [
            'reseller_id' => ['label' => 'Reseller ID'],
            'api_key' => ['label' => 'API key', 'secret' => true, 'help' => 'From Settings > API in the ResellerClub control panel, where this server\'s IP address must be allowed too.'],
            'test_mode' => ['label' => 'Use the test platform (test.httpapi.com)', 'type' => 'checkbox'],
        ];
    }

    public function available(array $names, array $credentials): array
    {
        $answers = [];
        // The API checks every name against every extension, so ask per name.
        foreach (collect($names)->groupBy(fn (string $name) => Str::before($name, '.')) as $sld => $group) {
            $reply = ResellerClubApi::get('domains/available.json', [self::DOMAIN_NAME => [$sld], 'tlds' => $group->map(fn ($name) => Str::after($name, '.'))->all()], $credentials);
            foreach ($group as $name) {
                if (isset($reply[$name]['status'])) {
                    $answers[$name] = $reply[$name]['status'] === 'available';
                }
            }
        }

        return $answers;
    }

    public function register(Domain $domain, array $credentials): RegistrarResult
    {
        [$customer, $contact] = ResellerClubApi::customerAndContact($domain, $credentials);
        $reply = ResellerClubApi::post('domains/register.json', [
            self::DOMAIN_NAME => $domain->name,
            'years' => $domain->years,
            'ns' => $domain->nameservers ?? [],
            'invoice-option' => 'NoInvoice',
            'protect-privacy' => $domain->id_protection ? 'true' : 'false',
        ] + ResellerClubApi::contactIds($customer, $contact), $credentials);

        return ResellerClubApi::orderResult($reply, false, ['customer_id' => $customer, 'contact_id' => $contact]);
    }

    public function transfer(Domain $domain, array $credentials): RegistrarResult
    {
        [$customer, $contact] = ResellerClubApi::customerAndContact($domain, $credentials);
        $reply = ResellerClubApi::post('domains/transfer.json', [
            self::DOMAIN_NAME => $domain->name,
            'auth-code' => (string) $domain->epp_code,
            'invoice-option' => 'NoInvoice',
            'protect-privacy' => $domain->id_protection ? 'true' : 'false',
        ] + ResellerClubApi::contactIds($customer, $contact), $credentials);

        return ResellerClubApi::orderResult($reply, true, ['customer_id' => $customer, 'contact_id' => $contact]);
    }

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult
    {
        $details = $this->details($domain, $credentials);
        $reply = ResellerClubApi::post('domains/renew.json', [
            self::ORDER_ID => $details['orderid'],
            'years' => $years,
            'exp-date' => $details['endtime'],
            'invoice-option' => 'NoInvoice',
        ], $credentials);
        $result = ResellerClubApi::orderResult($reply, false);

        return $result->ok ? RegistrarResult::done(CarbonImmutable::createFromTimestampUTC((int) $details['endtime'])->addYears($years)->startOfDay()) : $result;
    }

    public function info(Domain $domain, array $credentials): DomainInfo
    {
        try {
            $details = $this->details($domain, $credentials);
        } catch (RegistrarError $e) {
            if (preg_match('/doesn\'t exist|does not exist|not found|invalid domain/i', $e->getMessage())) {
                return new DomainInfo($domain->status === DomainStatus::PendingTransfer ? DomainInfo::PENDING_TRANSFER : DomainInfo::GONE);
            }
            throw $e;
        }

        $expiry = filled($details['endtime'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $details['endtime'])->startOfDay() : null;
        $status = strtolower((string) ($details['currentstatus'] ?? ''));
        $transferring = collect((array) ($details['orderstatus'] ?? []))->contains(fn ($s) => str_contains(strtolower((string) $s), 'transfer'))
            && $domain->status === DomainStatus::PendingTransfer;

        return new DomainInfo(
            match (true) {
                $status === 'deleted' => DomainInfo::GONE,
                $transferring || $status === 'pendingtransfer' => DomainInfo::PENDING_TRANSFER,
                $expiry !== null && $expiry->lt(today()) => DomainInfo::EXPIRED,
                default => DomainInfo::ACTIVE,
            },
            $expiry,
            collect(range(1, 13))->map(fn ($i) => strtolower((string) ($details["ns{$i}"] ?? '')))->filter()->values()->all(),
            in_array('transferlock', (array) ($details['orderstatus'] ?? []), true),
            isset($details['isprivacyprotected']) ? $details['isprivacyprotected'] === 'true' || $details['isprivacyprotected'] === true : null,
        );
    }

    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void
    {
        ResellerClubApi::post('domains/modify-ns.json', [self::ORDER_ID => $this->orderId($domain, $credentials), 'ns' => $nameservers], $credentials);
    }

    public function contact(Domain $domain, array $credentials): DomainContact
    {
        $contact = (array) ($this->details($domain, $credentials)['registrantcontact'] ?? []);

        return DomainContact::fromArray([
            'first_name' => Str::before((string) ($contact['name'] ?? ''), ' '),
            'last_name' => Str::contains((string) ($contact['name'] ?? ''), ' ') ? Str::after((string) $contact['name'], ' ') : '',
            'company' => $contact['company'] ?? '',
            'email' => $contact['emailaddr'] ?? '',
            'phone' => filled($contact['telno'] ?? null) ? '+'.($contact['telnocc'] ?? '').'.'.$contact['telno'] : '',
            'address1' => $contact['address1'] ?? '',
            'address2' => $contact['address2'] ?? '',
            'city' => $contact['city'] ?? '',
            'state' => $contact['state'] ?? '',
            'postcode' => $contact['zip'] ?? '',
            'country' => $contact['country'] ?? '',
        ]);
    }

    public function setContact(Domain $domain, DomainContact $contact, array $credentials): void
    {
        $id = $this->details($domain, $credentials)['registrantcontactid'] ?? $domain->registrarValue('contact_id')
            ?? throw new RegistrarError("ResellerClub has no registrant contact for {$domain->name}.");

        ResellerClubApi::post('contacts/modify.json', ['contact-id' => $id] + ResellerClubApi::contactFields($contact), $credentials);
    }

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void
    {
        ResellerClubApi::post('domains/modify-privacy-protection.json', [
            self::ORDER_ID => $this->orderId($domain, $credentials),
            'protect-privacy' => $on ? 'true' : 'false',
            'reason' => 'Changed by the domain owner',
        ], $credentials);
    }

    public function setLock(Domain $domain, bool $locked, array $credentials): void
    {
        ResellerClubApi::post($locked ? 'domains/enable-theft-protection.json' : 'domains/disable-theft-protection.json', [self::ORDER_ID => $this->orderId($domain, $credentials)], $credentials);
    }

    public function eppCode(Domain $domain, array $credentials): ?string
    {
        return $this->details($domain, $credentials)['domsecret'] ?? throw new RegistrarError("ResellerClub didn't return the EPP code for {$domain->name}.");
    }

    public function testConnection(array $credentials): void
    {
        ResellerClubApi::get('billing/reseller-balance.json', ['reseller-id' => [$credentials['reseller_id'] ?? '']], $credentials);
    }

    public function dnsRecords(Domain $domain, array $credentials): array
    {
        $records = [];
        foreach (array_keys(self::DNS_CALLS) as $type) {
            $reply = ResellerClubApi::get('dns/manage/search-records.json', [self::DOMAIN_NAME => [$domain->name], 'type' => [$type], 'no-of-records' => ['50'], 'page-no' => ['1']], $credentials);
            foreach ($reply as $key => $row) {
                if (is_numeric($key) && is_array($row)) {
                    $records[] = new DnsRecord($this->host((string) ($row['host'] ?? ''), $domain->name), $type, (string) ($row['value'] ?? ''), $type === 'MX' ? (int) ($row['priority'] ?? 10) : null, (int) ($row['timetolive'] ?? 3600));
                }
            }
        }

        return $records;
    }

    public function setDnsRecords(Domain $domain, array $records, array $credentials): void
    {
        // Turning DNS on for an order that already has it is refused harmlessly.
        try {
            ResellerClubApi::post('dns/activate.json', [self::ORDER_ID => $this->orderId($domain, $credentials)], $credentials);
        } catch (RegistrarError) {
            // Already active.
        }

        $key = fn (DnsRecord $r) => "{$r->type}|{$r->host}|{$r->value}|{$r->priority}";
        $current = collect($this->dnsRecords($domain, $credentials))->keyBy($key);
        $wanted = collect($records)->keyBy($key);

        foreach ($current->diffKeys($wanted) as $record) {
            ResellerClubApi::post('dns/manage/delete-'.self::DNS_CALLS[$record->type].'-record.json', [
                self::DOMAIN_NAME => $domain->name,
                'host' => $record->host === '@' ? '' : $record->host,
                'value' => $record->value,
            ], $credentials);
        }
        foreach ($wanted->diffKeys($current) as $record) {
            ResellerClubApi::post('dns/manage/add-'.self::DNS_CALLS[$record->type].'-record.json', array_filter([
                self::DOMAIN_NAME => $domain->name,
                'host' => $record->host === '@' ? '' : $record->host,
                'value' => $record->value,
                'ttl' => $record->ttl,
                'priority' => $record->priority,
            ], fn ($value) => $value !== null), $credentials);
        }
    }

    /** "www" for "www.example.com", "@" for the domain itself. */
    private function host(string $host, string $domain): string
    {
        $host = rtrim(strtolower($host), '.');

        return match (true) {
            $host === '' || $host === '@' || $host === $domain => '@',
            str_ends_with($host, '.'.$domain) => substr($host, 0, -strlen($domain) - 1),
            default => $host,
        };
    }

    private function orderId(Domain $domain, array $credentials): string
    {
        $id = $domain->registrarValue('order_id');
        if (blank($id)) {
            $id = (string) ResellerClubApi::get('domains/orderid.json', [self::DOMAIN_NAME => [$domain->name]], $credentials, true);
            $domain->rememberRegistrarValues(['order_id' => $id]);
        }

        return (string) $id;
    }

    /** @return array<string, mixed> */
    private function details(Domain $domain, array $credentials): array
    {
        $details = ResellerClubApi::get('domains/details-by-name.json', [self::DOMAIN_NAME => [$domain->name], 'options' => ['All']], $credentials);
        if (filled($details['orderid'] ?? null) && $domain->registrarValue('order_id') !== (string) $details['orderid']) {
            $domain->rememberRegistrarValues(['order_id' => (string) $details['orderid']]);
        }

        return $details;
    }
}
