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
use SimpleXMLElement;

/** Namecheap's XML API. Calls must come from an IP address whitelisted in the Namecheap account. */
class Namecheap implements ManagesDns, Registrar
{
    use XmlReplies;

    private const LIVE = 'https://api.namecheap.com/xml.response';

    private const SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';

    private const CONTACT_TYPES = ['Registrant', 'Tech', 'Admin', 'AuxBilling'];

    /** Error numbers for a domain that isn't (or is no longer) in the account. */
    private const NOT_OURS = ['2019166', '2016166', '2030166'];

    private const NAME = 'Namecheap';

    private const DOMAIN_NAME = 'DomainName';

    public function key(): string
    {
        return 'namecheap';
    }

    public function label(): string
    {
        return self::NAME;
    }

    public function configFields(): array
    {
        return [
            'api_user' => ['label' => 'API user (your Namecheap username)'],
            'api_key' => ['label' => 'API key', 'secret' => true, 'help' => 'From Profile > Tools > Namecheap API access.'],
            'client_ip' => ['label' => 'This server\'s public IP address', 'help' => 'Namecheap only accepts calls from whitelisted addresses; add this one there too.'],
            'sandbox' => ['label' => 'Use the Namecheap sandbox (api.sandbox.namecheap.com)', 'type' => 'checkbox'],
        ];
    }

    public function available(array $names, array $credentials): array
    {
        $answers = [];
        foreach (array_chunk($names, 50) as $chunk) {
            $xml = $this->call('namecheap.domains.check', ['DomainList' => implode(',', $chunk)], $credentials);
            foreach ($xml->xpath('//DomainCheckResult') ?: [] as $result) {
                // Premium names cost more than the TLD's price, so they aren't offered here.
                $answers[strtolower((string) $result['Domain'])] = strtolower((string) $result['Available']) === 'true'
                    && strtolower((string) $result['IsPremiumName']) !== 'true';
            }
        }

        return $answers;
    }

    public function register(Domain $domain, array $credentials): RegistrarResult
    {
        $xml = $this->call('namecheap.domains.create', [
            self::DOMAIN_NAME => $domain->name,
            'Years' => $domain->years,
            'Nameservers' => implode(',', $domain->nameservers ?? []),
            'AddFreeWhoisguard' => 'yes',
            'WGEnabled' => $domain->id_protection ? 'yes' : 'no',
        ] + $this->contactParams($domain->registrant(), self::CONTACT_TYPES), $credentials);

        if (strtolower((string) ($xml->xpath('//DomainCreateResult')[0]['Registered'] ?? '')) !== 'true') {
            return RegistrarResult::failed('Namecheap did not register the domain.');
        }

        return RegistrarResult::done(data: ['domain_id' => (string) ($xml->xpath('//DomainCreateResult')[0]['DomainID'] ?? '')]);
    }

    public function transfer(Domain $domain, array $credentials): RegistrarResult
    {
        $xml = $this->call('namecheap.domains.transfer.create', [
            self::DOMAIN_NAME => $domain->name,
            'Years' => 1,
            'EPPCode' => (string) $domain->epp_code,
            'AddFreeWhoisguard' => 'yes',
            'WGEnable' => $domain->id_protection ? 'yes' : 'no',
        ], $credentials);
        $result = $xml->xpath('//DomainTransferCreateResult')[0] ?? null;

        if ($result === null || strtolower((string) $result['Transfer']) !== 'true') {
            return RegistrarResult::failed('Namecheap did not accept the transfer: '.((string) ($result['StatusDescription'] ?? '') ?: 'no reason given'));
        }

        return RegistrarResult::inProgress(['transfer_id' => (string) $result['TransferID']]);
    }

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult
    {
        $xml = $this->call('namecheap.domains.renew', [self::DOMAIN_NAME => $domain->name, 'Years' => $years], $credentials);

        return RegistrarResult::done($this->date($this->text($xml, '//DomainRenewResult/DomainDetails/ExpiredDate')));
    }

    public function info(Domain $domain, array $credentials): DomainInfo
    {
        try {
            $xml = $this->call('namecheap.domains.getInfo', [self::DOMAIN_NAME => $domain->name], $credentials);
        } catch (RegistrarError $e) {
            if ($e->getCode() !== 0 && in_array((string) $e->getCode(), self::NOT_OURS, true)) {
                return new DomainInfo($domain->status === DomainStatus::PendingTransfer ? DomainInfo::PENDING_TRANSFER : DomainInfo::GONE);
            }
            throw $e;
        }

        $result = $xml->xpath('//DomainGetInfoResult')[0] ?? null;
        $expiry = $this->date($this->text($xml, '//DomainGetInfoResult/DomainDetails/ExpiredDate'));
        $whoisguard = $xml->xpath('//DomainGetInfoResult/Whoisguard')[0] ?? null;
        if ($whoisguard !== null && filled((string) $whoisguard->ID)) {
            $domain->rememberRegistrarValues(['whoisguard_id' => trim((string) $whoisguard->ID)]);
        }

        return new DomainInfo(
            strtolower((string) ($result['Status'] ?? '')) === 'expired' || ($expiry?->isPast() ?? false) ? DomainInfo::EXPIRED : DomainInfo::ACTIVE,
            $expiry,
            array_map(fn ($ns) => strtolower(trim((string) $ns)), $xml->xpath('//DomainGetInfoResult/DnsDetails/Nameserver') ?: []),
            null,
            $whoisguard !== null ? strtolower((string) $whoisguard['Enabled']) === 'true' : null,
        );
    }

    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void
    {
        $this->call('namecheap.domains.dns.setCustom', $this->names($domain) + ['Nameservers' => implode(',', $nameservers)], $credentials);
    }

    public function contact(Domain $domain, array $credentials): DomainContact
    {
        $xml = $this->call('namecheap.domains.getContacts', [self::DOMAIN_NAME => $domain->name], $credentials);
        $field = fn (string $name) => (string) $this->text($xml, "//DomainContactsResult/Registrant/{$name}");

        return DomainContact::fromArray([
            'first_name' => $field('FirstName'),
            'last_name' => $field('LastName'),
            'company' => $field('OrganizationName'),
            'email' => $field('EmailAddress'),
            'phone' => $field('Phone'),
            'address1' => $field('Address1'),
            'address2' => $field('Address2'),
            'city' => $field('City'),
            'state' => $field('StateProvince'),
            'postcode' => $field('PostalCode'),
            'country' => $field('Country'),
        ]);
    }

    public function setContact(Domain $domain, DomainContact $contact, array $credentials): void
    {
        // Namecheap replaces all four contacts at once.
        $this->call('namecheap.domains.setContacts', [self::DOMAIN_NAME => $domain->name] + $this->contactParams($contact, self::CONTACT_TYPES), $credentials);
    }

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void
    {
        $id = $domain->registrarValue('whoisguard_id');
        if (blank($id)) {
            $this->info($domain, $credentials);
            $id = $domain->registrarValue('whoisguard_id');
        }
        if (blank($id)) {
            throw new RegistrarError("Namecheap has no WhoisGuard subscription for {$domain->name}.");
        }

        $this->call($on ? 'namecheap.whoisguard.enable' : 'namecheap.whoisguard.disable', ['WhoisguardID' => $id] + ($on ? ['ForwardedToEmail' => $domain->registrant()->email] : []), $credentials);
    }

    public function setLock(Domain $domain, bool $locked, array $credentials): void
    {
        $this->call('namecheap.domains.setRegistrarLock', [self::DOMAIN_NAME => $domain->name, 'LockAction' => $locked ? 'LOCK' : 'UNLOCK'], $credentials);
    }

    public function eppCode(Domain $domain, array $credentials): ?string
    {
        throw new RegistrarError(__("Namecheap doesn't give out EPP codes through its API. Ask our support team for it."));
    }

    public function testConnection(array $credentials): void
    {
        $this->call('namecheap.users.getBalances', [], $credentials);
    }

    public function dnsRecords(Domain $domain, array $credentials): array
    {
        $xml = $this->call('namecheap.domains.dns.getHosts', $this->names($domain), $credentials);
        $records = [];
        foreach ($xml->xpath('//DomainDNSGetHostsResult/host') ?: [] as $host) {
            $type = strtoupper((string) $host['Type']);
            if (in_array($type, DnsRecord::TYPES, true)) {
                $records[] = new DnsRecord((string) $host['Name'] ?: '@', $type, (string) $host['Address'], $type === 'MX' ? (int) $host['MXPref'] : null, (int) $host['TTL'] ?: 1800);
            }
        }

        return $records;
    }

    public function setDnsRecords(Domain $domain, array $records, array $credentials): void
    {
        $params = $this->names($domain);
        foreach (array_values($records) as $i => $record) {
            $n = $i + 1;
            $params += [
                "HostName{$n}" => $record->host,
                "RecordType{$n}" => $record->type,
                "Address{$n}" => $record->value,
                "TTL{$n}" => $record->ttl,
            ];
            if ($record->type === 'MX') {
                $params["MXPref{$n}"] = $record->priority ?? 10;
                $params['EmailType'] = 'MX';
            }
        }

        $this->call('namecheap.domains.dns.setHosts', $params, $credentials);
    }

    /** @return array{SLD: string, TLD: string} */
    private function names(Domain $domain): array
    {
        return ['SLD' => $domain->sld(), 'TLD' => $domain->extension()];
    }

    /** @param  list<string>  $types */
    private function contactParams(DomainContact $contact, array $types): array
    {
        $params = [];
        foreach ($types as $type) {
            $params += [
                "{$type}FirstName" => $contact->firstName,
                "{$type}LastName" => $contact->lastName,
                "{$type}OrganizationName" => $contact->company,
                "{$type}Address1" => $contact->address1,
                "{$type}Address2" => $contact->address2,
                "{$type}City" => $contact->city,
                "{$type}StateProvince" => $contact->state ?: $contact->city,
                "{$type}PostalCode" => $contact->postcode,
                "{$type}Country" => $contact->country,
                "{$type}Phone" => $contact->registrarPhone(),
                "{$type}EmailAddress" => $contact->email,
            ];
        }

        return array_filter($params, fn ($value) => $value !== '');
    }

    /** Namecheap writes dates as "MM/DD/YYYY". */
    private function date(?string $value): ?CarbonImmutable
    {
        if (blank($value) || ! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $value, $m)) {
            return null;
        }

        return CarbonImmutable::create((int) $m[3], (int) $m[1], (int) $m[2]);
    }

    /** @param  array<string, string|int>  $params */
    private function call(string $command, array $params, array $credentials): SimpleXMLElement
    {
        try {
            $response = Http::timeout(30)->asForm()->post(! empty($credentials['sandbox']) ? self::SANDBOX : self::LIVE, [
                'ApiUser' => $credentials['api_user'] ?? '',
                'ApiKey' => $credentials['api_key'] ?? '',
                'UserName' => $credentials['api_user'] ?? '',
                'ClientIp' => $credentials['client_ip'] ?? '',
                'Command' => $command,
            ] + $params);
        } catch (ConnectionException $e) {
            throw new RegistrarError("Namecheap couldn't be reached: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RegistrarError("Namecheap answered HTTP {$response->status()} to {$command}.");
        }

        $xml = $this->xml($response->body(), self::NAME);

        if (strtoupper((string) $xml['Status']) !== 'OK') {
            $error = $xml->xpath('/ApiResponse/Errors/Error')[0] ?? null;

            throw new RegistrarError('Namecheap refused '.$command.': '.(trim((string) $error) ?: 'no reason given'), (int) ($error['Number'] ?? 0));
        }

        return $xml;
    }
}
