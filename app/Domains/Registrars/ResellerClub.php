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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ResellerClub's HTTP API (LogicBoxes, also used by other brands on the
 * same platform). Each client becomes a customer in the reseller account,
 * and each domain gets its own contact.
 */
class ResellerClub implements ManagesDns, Registrar
{
    private const LIVE = 'https://httpapi.com/api/';

    private const TEST = 'https://test.httpapi.com/api/';

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
            $reply = $this->get('domains/available.json', [self::DOMAIN_NAME => [$sld], 'tlds' => $group->map(fn ($name) => Str::after($name, '.'))->all()], $credentials);
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
        [$customer, $contact] = $this->customerAndContact($domain, $credentials);
        $reply = $this->post('domains/register.json', [
            self::DOMAIN_NAME => $domain->name,
            'years' => $domain->years,
            'ns' => $domain->nameservers ?? [],
            'invoice-option' => 'NoInvoice',
            'protect-privacy' => $domain->id_protection ? 'true' : 'false',
        ] + $this->contactIds($customer, $contact), $credentials);

        return $this->orderResult($reply, false, ['customer_id' => $customer, 'contact_id' => $contact]);
    }

    public function transfer(Domain $domain, array $credentials): RegistrarResult
    {
        [$customer, $contact] = $this->customerAndContact($domain, $credentials);
        $reply = $this->post('domains/transfer.json', [
            self::DOMAIN_NAME => $domain->name,
            'auth-code' => (string) $domain->epp_code,
            'invoice-option' => 'NoInvoice',
            'protect-privacy' => $domain->id_protection ? 'true' : 'false',
        ] + $this->contactIds($customer, $contact), $credentials);

        return $this->orderResult($reply, true, ['customer_id' => $customer, 'contact_id' => $contact]);
    }

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult
    {
        $details = $this->details($domain, $credentials);
        $reply = $this->post('domains/renew.json', [
            self::ORDER_ID => $details['orderid'],
            'years' => $years,
            'exp-date' => $details['endtime'],
            'invoice-option' => 'NoInvoice',
        ], $credentials);
        $result = $this->orderResult($reply, false);

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
                $expiry !== null && $expiry->isPast() => DomainInfo::EXPIRED,
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
        $this->post('domains/modify-ns.json', [self::ORDER_ID => $this->orderId($domain, $credentials), 'ns' => $nameservers], $credentials);
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

        $this->post('contacts/modify.json', ['contact-id' => $id] + $this->contactFields($contact), $credentials);
    }

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void
    {
        $this->post('domains/modify-privacy-protection.json', [
            self::ORDER_ID => $this->orderId($domain, $credentials),
            'protect-privacy' => $on ? 'true' : 'false',
            'reason' => 'Changed by the domain owner',
        ], $credentials);
    }

    public function setLock(Domain $domain, bool $locked, array $credentials): void
    {
        $this->post($locked ? 'domains/enable-theft-protection.json' : 'domains/disable-theft-protection.json', [self::ORDER_ID => $this->orderId($domain, $credentials)], $credentials);
    }

    public function eppCode(Domain $domain, array $credentials): ?string
    {
        return $this->details($domain, $credentials)['domsecret'] ?? throw new RegistrarError("ResellerClub didn't return the EPP code for {$domain->name}.");
    }

    public function testConnection(array $credentials): void
    {
        $this->get('billing/reseller-balance.json', ['reseller-id' => [$credentials['reseller_id'] ?? '']], $credentials);
    }

    public function dnsRecords(Domain $domain, array $credentials): array
    {
        $records = [];
        foreach (array_keys(self::DNS_CALLS) as $type) {
            $reply = $this->get('dns/manage/search-records.json', [self::DOMAIN_NAME => [$domain->name], 'type' => [$type], 'no-of-records' => ['50'], 'page-no' => ['1']], $credentials);
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
            $this->post('dns/activate.json', [self::ORDER_ID => $this->orderId($domain, $credentials)], $credentials);
        } catch (RegistrarError) {
            // Already active.
        }

        $key = fn (DnsRecord $r) => "{$r->type}|{$r->host}|{$r->value}|{$r->priority}";
        $current = collect($this->dnsRecords($domain, $credentials))->keyBy($key);
        $wanted = collect($records)->keyBy($key);

        foreach ($current->diffKeys($wanted) as $record) {
            $this->post('dns/manage/delete-'.self::DNS_CALLS[$record->type].'-record.json', [
                self::DOMAIN_NAME => $domain->name,
                'host' => $record->host === '@' ? '' : $record->host,
                'value' => $record->value,
            ], $credentials);
        }
        foreach ($wanted->diffKeys($current) as $record) {
            $this->post('dns/manage/add-'.self::DNS_CALLS[$record->type].'-record.json', array_filter([
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

    /**
     * The client's customer in the reseller account (found by email, else
     * made) and a contact for this domain.
     *
     * @return array{0: string, 1: string}
     */
    private function customerAndContact(Domain $domain, array $credentials): array
    {
        $contact = $domain->registrant();
        $customer = $domain->registrarValue('customer_id');

        if (blank($customer)) {
            try {
                $customer = (string) ($this->get('customers/details.json', ['username' => [$contact->email]], $credentials)['customerid'] ?? '');
            } catch (RegistrarError) {
                $customer = '';
            }
        }
        if ($customer === '') {
            $customer = (string) $this->post('customers/v2/signup.json', [
                'username' => $contact->email,
                'passwd' => Str::password(16, symbols: false).'a1',
                'name' => trim("{$contact->firstName} {$contact->lastName}"),
                'company' => $contact->company ?: 'N/A',
                'address-line-1' => $contact->address1,
                'city' => $contact->city,
                'state' => $contact->state ?: 'Not Applicable',
                'country' => $contact->country,
                'zipcode' => $contact->postcode,
                'lang-pref' => 'en',
            ] + $this->phone($contact), $credentials, true);
        }

        $contactId = $domain->registrarValue('contact_id') ?: (string) $this->post('contacts/add.json', [
            'customer-id' => $customer,
            'type' => 'Contact',
        ] + $this->contactFields($contact), $credentials, true);
        $domain->rememberRegistrarValues(['customer_id' => $customer, 'contact_id' => $contactId]);

        return [$customer, $contactId];
    }

    private function contactIds(string $customer, string $contact): array
    {
        return [
            'customer-id' => $customer,
            'reg-contact-id' => $contact,
            'admin-contact-id' => $contact,
            'tech-contact-id' => $contact,
            'billing-contact-id' => $contact,
        ];
    }

    private function contactFields(DomainContact $contact): array
    {
        return [
            'name' => trim("{$contact->firstName} {$contact->lastName}"),
            'company' => $contact->company ?: 'N/A',
            'email' => $contact->email,
            'address-line-1' => $contact->address1,
            'address-line-2' => $contact->address2,
            'city' => $contact->city,
            'state' => $contact->state,
            'country' => $contact->country,
            'zipcode' => $contact->postcode,
        ] + $this->phone($contact);
    }

    /** @return array{phone-cc: string, phone: string} */
    private function phone(DomainContact $contact): array
    {
        [$code, $number] = array_pad(explode('.', ltrim($contact->registrarPhone(), '+'), 2), 2, '');

        return ['phone-cc' => $code, 'phone' => $number];
    }

    /** @param  array<string, mixed>  $reply */
    private function orderResult(array $reply, bool $transfer, array $data = []): RegistrarResult
    {
        $status = strtolower((string) ($reply['actionstatus'] ?? $reply['status'] ?? ''));

        if (in_array($status, ['failed', 'error'], true)) {
            return RegistrarResult::failed('ResellerClub refused it: '.($reply['actionstatusdesc'] ?? $reply['message'] ?? 'no reason given'));
        }

        $data += ['order_id' => (string) ($reply['entityid'] ?? '')];

        return $transfer ? RegistrarResult::inProgress($data) : RegistrarResult::done(data: $data);
    }

    private function orderId(Domain $domain, array $credentials): string
    {
        $id = $domain->registrarValue('order_id');
        if (blank($id)) {
            $id = (string) $this->get('domains/orderid.json', [self::DOMAIN_NAME => [$domain->name]], $credentials, true);
            $domain->rememberRegistrarValues(['order_id' => $id]);
        }

        return (string) $id;
    }

    /** @return array<string, mixed> */
    private function details(Domain $domain, array $credentials): array
    {
        $details = $this->get('domains/details-by-name.json', [self::DOMAIN_NAME => [$domain->name], 'options' => ['All']], $credentials);
        if (filled($details['orderid'] ?? null) && $domain->registrarValue('order_id') !== (string) $details['orderid']) {
            $domain->rememberRegistrarValues(['order_id' => (string) $details['orderid']]);
        }

        return $details;
    }

    /**
     * @param  array<string, string|int|list<string>>  $params  A list repeats the name, as the API wants for ns and tlds.
     */
    private function get(string $path, array $params, array $credentials, bool $plain = false): mixed
    {
        return $this->send('GET', $path, $params, $credentials, $plain);
    }

    private function post(string $path, array $params, array $credentials, bool $plain = false): mixed
    {
        return $this->send('POST', $path, $params, $credentials, $plain);
    }

    /** @param  bool  $plain  The call answers with a bare value, such as a new id. */
    private function send(string $method, string $path, array $params, array $credentials, bool $plain): mixed
    {
        $query = $this->query(['auth-userid' => $credentials['reseller_id'] ?? '', 'api-key' => $credentials['api_key'] ?? ''] + $params);
        $url = (! empty($credentials['test_mode']) ? self::TEST : self::LIVE).$path;

        try {
            $request = Http::timeout(30)->acceptJson();
            $response = $method === 'GET'
                ? $request->get("{$url}?{$query}")
                : $request->withBody($query, 'application/x-www-form-urlencoded')->post($url);
        } catch (ConnectionException $e) {
            throw new RegistrarError("ResellerClub couldn't be reached: {$e->getMessage()}");
        }

        $json = $response->json();

        if (is_array($json) && in_array(strtolower((string) ($json['status'] ?? '')), ['error', 'failed'], true) && ! isset($json['actionstatus'])) {
            throw new RegistrarError('ResellerClub refused '.$path.': '.($json['message'] ?? $json['error'] ?? 'no reason given'));
        }
        if (! $response->successful()) {
            throw new RegistrarError("ResellerClub answered HTTP {$response->status()} to {$path}.");
        }

        return $plain ? trim($response->body(), "\" \n") : (array) $json;
    }

    /** a=1&ns=x&ns=y: the API wants repeated names, not PHP's ns[0]=x. */
    private function query(array $params): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            foreach ((array) $value as $one) {
                $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $one);
            }
        }

        return implode('&', $pairs);
    }
}
