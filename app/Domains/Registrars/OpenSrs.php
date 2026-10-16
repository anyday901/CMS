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
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** OpenSRS (Tucows) over its XML API, signed with the reseller's API key. */
class OpenSrs implements ManagesDns, Registrar
{
    private const LIVE = 'https://rr-n1-tor.opensrs.net:55443';

    private const TEST = 'https://horizon.opensrs.net:55443';

    /** Response codes for a name that's free, and one that's taken. */
    private const AVAILABLE = '210';

    private const TAKEN = '211';

    /** How each record type keeps its value in a DNS zone. */
    private const ZONE_FIELDS = ['A' => 'ip_address', 'AAAA' => 'ipv6_address', 'CNAME' => 'hostname', 'MX' => 'hostname', 'TXT' => 'text'];

    private const DOMAIN = 'DOMAIN';

    public function key(): string
    {
        return 'opensrs';
    }

    public function label(): string
    {
        return 'OpenSRS';
    }

    public function configFields(): array
    {
        return [
            'username' => ['label' => 'Reseller username'],
            'api_key' => ['label' => 'API key', 'secret' => true, 'help' => 'From the Reseller Control Panel: Profile Management > API settings, where this server\'s IP address must be allowed too.'],
            'test_mode' => ['label' => 'Use the OpenSRS test environment (horizon.opensrs.net)', 'type' => 'checkbox'],
        ];
    }

    public function available(array $names, array $credentials): array
    {
        $answers = [];
        foreach ($names as $name) {
            $reply = $this->call('LOOKUP', self::DOMAIN, ['domain' => $name], $credentials, [self::AVAILABLE, self::TAKEN]);
            $answers[$name] = (string) $reply['response_code'] === self::AVAILABLE;
        }

        return $answers;
    }

    public function register(Domain $domain, array $credentials): RegistrarResult
    {
        $reply = $this->call('SW_REGISTER', self::DOMAIN, $this->registration($domain, 'new') + ['period' => $domain->years], $credentials);

        return RegistrarResult::done(data: ['order_id' => (string) ($reply['attributes']['id'] ?? '')] + $this->profile($domain));
    }

    public function transfer(Domain $domain, array $credentials): RegistrarResult
    {
        $reply = $this->call('SW_REGISTER', self::DOMAIN, $this->registration($domain, 'transfer') + [
            'period' => 1,
            'auth_info' => (string) $domain->epp_code,
        ], $credentials);

        return RegistrarResult::inProgress(['order_id' => (string) ($reply['attributes']['id'] ?? '')] + $this->profile($domain));
    }

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult
    {
        $current = $domain->expiry_date ?? $this->info($domain, $credentials)->expiry
            ?? throw new RegistrarError("OpenSRS didn't say when {$domain->name} expires.");
        $reply = $this->call('RENEW', self::DOMAIN, [
            'domain' => $domain->name,
            'period' => $years,
            'currentexpirationyear' => $current->year,
            'handle' => 'process',
            'auto_renew' => 0,
        ], $credentials);

        return RegistrarResult::done($this->date($reply['attributes']['registration expiration date'] ?? null));
    }

    public function info(Domain $domain, array $credentials): DomainInfo
    {
        try {
            $info = $this->get($domain, 'all_info', $credentials);
        } catch (RegistrarError $e) {
            if (preg_match('/not found|does not exist|not in your account|authentication error/i', $e->getMessage())) {
                return new DomainInfo($domain->status === DomainStatus::PendingTransfer ? DomainInfo::PENDING_TRANSFER : DomainInfo::GONE);
            }
            throw $e;
        }

        $expiry = $this->date($info['expiredate'] ?? null);
        $status = $this->get($domain, 'status', $credentials);
        $privacy = $this->get($domain, 'whois_privacy_state', $credentials);

        return new DomainInfo(
            $expiry !== null && $expiry->isPast() ? DomainInfo::EXPIRED : DomainInfo::ACTIVE,
            $expiry,
            collect($info['nameserver_list'] ?? [])->map(fn ($ns) => strtolower((string) ($ns['name'] ?? '')))->filter()->values()->all(),
            isset($status['lock_state']) ? (string) $status['lock_state'] === '1' : null,
            isset($privacy['state']) ? $privacy['state'] === 'enabled' : null,
        );
    }

    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void
    {
        $this->call('ADVANCED_UPDATE_NAMESERVERS', self::DOMAIN, ['domain' => $domain->name, 'op_type' => 'assign', 'assign_ns' => array_values($nameservers)], $credentials);
    }

    public function contact(Domain $domain, array $credentials): DomainContact
    {
        $owner = (array) ($this->get($domain, 'all_info', $credentials)['contact_set']['owner'] ?? []);

        return DomainContact::fromArray([
            'first_name' => $owner['first_name'] ?? '',
            'last_name' => $owner['last_name'] ?? '',
            'company' => $owner['org_name'] ?? '',
            'email' => $owner['email'] ?? '',
            'phone' => $owner['phone'] ?? '',
            'address1' => $owner['address1'] ?? '',
            'address2' => $owner['address2'] ?? '',
            'city' => $owner['city'] ?? '',
            'state' => $owner['state'] ?? '',
            'postcode' => $owner['postal_code'] ?? '',
            'country' => $owner['country'] ?? '',
        ]);
    }

    public function setContact(Domain $domain, DomainContact $contact, array $credentials): void
    {
        $this->call('MODIFY', self::DOMAIN, [
            'domain' => $domain->name,
            'data' => 'contact_info',
            'affect_domains' => 0,
            'contact_set' => ['owner' => $this->contactFields($contact)],
        ], $credentials);
    }

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void
    {
        $this->call('MODIFY', self::DOMAIN, ['domain' => $domain->name, 'data' => 'whois_privacy_state', 'state' => $on ? 'enable' : 'disable', 'affect_domains' => 0], $credentials);
    }

    public function setLock(Domain $domain, bool $locked, array $credentials): void
    {
        $this->call('MODIFY', self::DOMAIN, ['domain' => $domain->name, 'data' => 'status', 'lock_state' => $locked ? 1 : 0, 'affect_domains' => 0], $credentials);
    }

    public function eppCode(Domain $domain, array $credentials): ?string
    {
        return $this->get($domain, 'domain_auth_info', $credentials)['domain_auth_info']
            ?? throw new RegistrarError("OpenSRS didn't return the EPP code for {$domain->name}.");
    }

    public function testConnection(array $credentials): void
    {
        $this->call('GET_BALANCE', 'BALANCE', [], $credentials);
    }

    public function dnsRecords(Domain $domain, array $credentials): array
    {
        try {
            $reply = $this->call('GET_DNS_ZONE', self::DOMAIN, ['domain' => $domain->name], $credentials);
        } catch (RegistrarError $e) {
            if (preg_match('/does not exist|not found|no dns/i', $e->getMessage())) {
                return [];
            }
            throw $e;
        }

        $records = [];
        foreach (self::ZONE_FIELDS as $type => $field) {
            foreach ((array) ($reply['attributes']['records'][$type] ?? []) as $row) {
                $records[] = new DnsRecord((string) ($row['subdomain'] ?? '') ?: '@', $type, (string) ($row[$field] ?? ''), $type === 'MX' ? (int) ($row['priority'] ?? 10) : null);
            }
        }

        return $records;
    }

    public function setDnsRecords(Domain $domain, array $records, array $credentials): void
    {
        $zone = [];
        foreach ($records as $record) {
            $zone[$record->type][] = array_filter([
                'subdomain' => $record->host === '@' ? '' : $record->host,
                self::ZONE_FIELDS[$record->type] => $record->value,
                'priority' => $record->priority,
            ], fn ($value) => $value !== null);
        }
        $attributes = ['domain' => $domain->name, 'records' => $zone];

        try {
            $this->call('SET_DNS_ZONE', self::DOMAIN, $attributes, $credentials);
        } catch (RegistrarError $e) {
            if (! preg_match('/does not exist|not found|no dns/i', $e->getMessage())) {
                throw $e;
            }
            // The domain has no zone at OpenSRS yet: make it with these records.
            $this->call('CREATE_DNS_ZONE', self::DOMAIN, $attributes, $credentials);
        }
    }

    /** SW_REGISTER attributes shared by registrations and transfers. */
    private function registration(Domain $domain, string $type): array
    {
        $contact = $this->contactFields($domain->registrant());
        $profile = $this->profile($domain);

        return [
            'domain' => $domain->name,
            'reg_type' => $type,
            'reg_username' => $profile['reg_username'],
            'reg_password' => $profile['reg_password'],
            'contact_set' => ['owner' => $contact, 'admin' => $contact, 'billing' => $contact, 'tech' => $contact],
            'custom_tech_contact' => 1,
            'custom_nameservers' => 1,
            'nameserver_list' => collect($domain->nameservers ?? [])->values()->map(fn ($ns, $i) => ['sortorder' => $i + 1, 'name' => $ns])->all(),
            'f_whois_privacy' => $domain->id_protection ? 1 : 0,
            'auto_renew' => 0,
            'handle' => 'process',
        ];
    }

    /**
     * The OpenSRS profile login for the domain, made once and kept, since
     * OpenSRS asks for one with each registration.
     *
     * @return array{reg_username: string, reg_password: string}
     */
    private function profile(Domain $domain): array
    {
        $profile = [
            'reg_username' => $domain->registrarValue('reg_username') ?: 'cms'.$domain->client_id.Str::lower(Str::random(6)),
            'reg_password' => $domain->registrarValue('reg_password') ?: Str::password(16, symbols: false),
        ];
        $domain->rememberRegistrarValues($profile);

        return $profile;
    }

    private function contactFields(DomainContact $contact): array
    {
        return [
            'first_name' => $contact->firstName,
            'last_name' => $contact->lastName,
            'org_name' => $contact->company ?: trim("{$contact->firstName} {$contact->lastName}"),
            'address1' => $contact->address1,
            'address2' => $contact->address2,
            'city' => $contact->city,
            'state' => $contact->state,
            'postal_code' => $contact->postcode,
            'country' => $contact->country,
            'phone' => $contact->registrarPhone(),
            'email' => $contact->email,
        ];
    }

    /** @return array<string, mixed> */
    private function get(Domain $domain, string $type, array $credentials): array
    {
        return (array) ($this->call('GET', self::DOMAIN, ['domain' => $domain->name, 'type' => $type], $credentials)['attributes'] ?? []);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse(substr((string) $value, 0, 10));
    }

    /**
     * Sends one command and returns the reply as an array.
     *
     * @param  list<string>  $accept  Response codes that aren't errors although is_success is 0.
     */
    private function call(string $action, string $object, array $attributes, array $credentials, array $accept = []): array
    {
        $xml = self::encode(['protocol' => 'XCP', 'action' => $action, 'object' => $object, 'attributes' => $attributes]);
        $key = (string) ($credentials['api_key'] ?? '');

        try {
            $response = Http::timeout(30)->withHeaders([
                'X-Username' => (string) ($credentials['username'] ?? ''),
                'X-Signature' => self::signature($xml, $key),
            ])->withBody($xml, 'text/xml')->post(! empty($credentials['test_mode']) ? self::TEST : self::LIVE);
        } catch (ConnectionException $e) {
            throw new RegistrarError("OpenSRS couldn't be reached: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RegistrarError("OpenSRS answered HTTP {$response->status()} to {$action}.");
        }

        $reply = self::decode($response->body());

        if ((string) ($reply['is_success'] ?? '0') !== '1' && ! in_array((string) ($reply['response_code'] ?? ''), $accept, true)) {
            throw new RegistrarError("OpenSRS refused {$action}: ".($reply['response_text'] ?? 'no reason given'));
        }

        return $reply;
    }

    /**
     * The X-Signature header OpenSRS requires: md5(md5(body + key) + key).
     * The algorithm is fixed by the OpenSRS API; it authenticates the request
     * over HTTPS and doesn't store or protect any secret.
     */
    public static function signature(string $body, string $key): string
    {
        return md5(md5($body.$key).$key); // NOSONAR: the OpenSRS API mandates this signature scheme.
    }

    /** The OPS envelope around a data block. */
    public static function encode(array $data): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $envelope = $dom->appendChild($dom->createElement('OPS_envelope'));
        $header = $envelope->appendChild($dom->createElement('header'));
        $header->appendChild($dom->createElement('version', '0.9'));
        $block = $envelope->appendChild($dom->createElement('body'))->appendChild($dom->createElement('data_block'));
        $block->appendChild(self::encodeValue($dom, $data));

        return "<?xml version='1.0' encoding='UTF-8' standalone='no' ?>\n<!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'>\n".$dom->saveXML($envelope);
    }

    private static function encodeValue(DOMDocument $dom, array $value): DOMElement
    {
        $node = $dom->createElement(array_is_list($value) && $value !== [] ? 'dt_array' : 'dt_assoc');
        foreach ($value as $key => $item) {
            $child = $dom->createElement('item');
            $child->setAttribute('key', (string) $key);
            if (is_array($item)) {
                $child->appendChild(self::encodeValue($dom, $item));
            } else {
                $child->appendChild($dom->createTextNode((string) $item));
            }
            $node->appendChild($child);
        }

        return $node;
    }

    /** The data block of an OPS reply as nested arrays. */
    public static function decode(string $body): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // No network, and no entity expansion from the DOCTYPE.
        $loaded = $body !== '' && $dom->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $block = $loaded ? $dom->getElementsByTagName('data_block')->item(0) : null;
        $root = $block ? self::firstElement($block) : null;

        if ($root === null) {
            throw new RegistrarError("OpenSRS sent a reply that isn't an OPS envelope.");
        }

        return (array) self::decodeValue($root);
    }

    private static function decodeValue(DOMElement $node): array|string
    {
        if (! in_array($node->nodeName, ['dt_assoc', 'dt_array'], true)) {
            return trim($node->textContent);
        }

        $value = [];
        foreach ($node->childNodes as $item) {
            if ($item instanceof DOMElement && $item->nodeName === 'item') {
                $inner = self::firstElement($item);
                $value[$item->getAttribute('key')] = $inner ? self::decodeValue($inner) : trim($item->textContent);
            }
        }

        return $value;
    }

    private static function firstElement(DOMElement $node): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }
}
