<?php

namespace App\Domains\Registrars;

use App\Domains\DnsRecord;
use App\Domains\DomainContact;
use App\Domains\DomainInfo;
use App\Domains\ManagesDns;
use App\Domains\Registrar;
use App\Domains\RegistrarError;
use App\Domains\RegistrarResult;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/** Enom's reseller API (interface.asp), with XML replies. */
class Enom implements ManagesDns, Registrar
{
    use XmlReplies;

    private const LIVE = 'https://reseller.enom.com/interface.asp';

    private const TEST = 'https://resellertest.enom.com/interface.asp';

    private const CONTACT_TYPES = ['Registrant', 'Admin', 'Tech', 'AuxBilling'];

    private const NAME = 'Enom';

    private const SLD = 'SLD';

    private const TLD = 'TLD';

    public function key(): string
    {
        return 'enom';
    }

    public function label(): string
    {
        return self::NAME;
    }

    public function configFields(): array
    {
        return [
            'username' => ['label' => 'Reseller login ID'],
            'api_token' => ['label' => 'API token', 'secret' => true, 'help' => 'From the Enom reseller control panel: Resellers > API token. Your server\'s IP address must be allowed there too.'],
            'test_mode' => ['label' => 'Use Enom\'s test environment (resellertest.enom.com)', 'type' => 'checkbox'],
        ];
    }

    public function available(array $names, array $credentials): array
    {
        $answers = [];
        foreach (array_chunk($names, 30) as $chunk) {
            $xml = $this->call('Check', ['DomainList' => implode(',', $chunk)], $credentials);
            $count = (int) $this->text($xml, '/interface-response/DomainCount');
            for ($i = 1; $i <= max($count, count($chunk)); $i++) {
                $name = strtolower((string) $this->text($xml, "/interface-response/Domain{$i}"));
                $code = $this->text($xml, "/interface-response/RRPCode{$i}");
                if ($name !== '' && $code !== null) {
                    $answers[$name] = $code === '210';
                }
            }
        }

        return $answers;
    }

    public function register(Domain $domain, array $credentials): RegistrarResult
    {
        $xml = $this->call('Purchase', $this->names($domain) + [
            'NumYears' => $domain->years,
            'IgnoreNSFail' => 'Yes',
        ] + $this->nameserverParams($domain->nameservers ?? []) + $this->contactParams($domain->registrant(), self::CONTACT_TYPES), $credentials);

        $result = RegistrarResult::done(data: ['order_id' => $this->text($xml, '/interface-response/OrderID')]);
        if ($domain->id_protection) {
            $this->setIdProtection($domain, true, $credentials);
        }

        return $result;
    }

    public function transfer(Domain $domain, array $credentials): RegistrarResult
    {
        $xml = $this->call('TP_CreateOrder', [
            'OrderType' => 'Autoverification',
            'DomainCount' => 1,
            'SLD1' => $domain->sld(),
            'TLD1' => $domain->extension(),
            'AuthInfo1' => (string) $domain->epp_code,
            'UseContacts' => 1,
        ], $credentials);

        return RegistrarResult::inProgress(['transfer_order_id' => $this->text($xml, '/interface-response/transferorder/transferorderid') ?? $this->text($xml, '//TransferOrderID')]);
    }

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult
    {
        $xml = $this->call('Extend', $this->names($domain) + ['NumYears' => $years], $credentials);

        return RegistrarResult::done(data: ['order_id' => $this->text($xml, '/interface-response/OrderID')]);
    }

    public function info(Domain $domain, array $credentials): DomainInfo
    {
        try {
            $xml = $this->call('GetDomainInfo', $this->names($domain), $credentials);
        } catch (RegistrarError $e) {
            if (preg_match('/not found|not in your account|doesn\'t belong|does not belong/i', $e->getMessage())) {
                return new DomainInfo($domain->status->value === 'pending_transfer' ? DomainInfo::PENDING_TRANSFER : DomainInfo::GONE);
            }
            throw $e;
        }

        $expiry = $this->date($this->text($xml, '//GetDomainInfo/status/expiration'));
        $state = strtolower((string) $this->text($xml, '//GetDomainInfo/status/registrationstatus'));
        $nameservers = array_map(fn ($ns) => strtolower(trim((string) $ns)), $xml->xpath("//services/entry[@name='dnsserver']//dns") ?: []);

        return new DomainInfo(
            match (true) {
                str_contains($state, 'expired') => DomainInfo::EXPIRED,
                $expiry !== null && $expiry->isPast() => DomainInfo::EXPIRED,
                default => DomainInfo::ACTIVE,
            },
            $expiry,
            array_values(array_filter($nameservers)),
            $this->lock($domain, $credentials),
        );
    }

    private function lock(Domain $domain, array $credentials): ?bool
    {
        try {
            $value = $this->text($this->call('GetRegLock', $this->names($domain), $credentials), '/interface-response/reg-lock');
        } catch (RegistrarError) {
            return null;
        }

        return $value === null ? null : $value === '1';
    }

    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void
    {
        $this->call('ModifyNS', $this->names($domain) + $this->nameserverParams($nameservers), $credentials);
    }

    public function contact(Domain $domain, array $credentials): DomainContact
    {
        $xml = $this->call('GetContacts', $this->names($domain), $credentials);
        $field = fn (string $name) => (string) $this->text($xml, "//GetContacts/Registrant/Registrant{$name}");

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
        $this->call('Contacts', $this->names($domain) + ['ContactType' => 'REGISTRANT'] + $this->contactParams($contact, ['Registrant']), $credentials);
    }

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void
    {
        $this->call($on ? 'EnableServices' : 'DisableServices', $this->names($domain) + ['Service' => 'WPPS'], $credentials);
    }

    public function setLock(Domain $domain, bool $locked, array $credentials): void
    {
        $this->call('SetRegLock', $this->names($domain) + ['UnlockRegistrar' => $locked ? 0 : 1], $credentials);
    }

    public function eppCode(Domain $domain, array $credentials): ?string
    {
        // Enom resets the code and emails it to the registrant rather than returning it.
        $this->call('SynchAuthInfo', $this->names($domain) + ['EmailEPP' => 'True', 'RunSynchAutoInfo' => 'True'], $credentials);

        return null;
    }

    public function testConnection(array $credentials): void
    {
        $this->call('GetBalance', [], $credentials);
    }

    public function dnsRecords(Domain $domain, array $credentials): array
    {
        $xml = $this->call('GetHosts', $this->names($domain), $credentials);
        $records = [];
        foreach ($xml->xpath('//host') ?: [] as $host) {
            $type = strtoupper(trim((string) $host->type));
            if (in_array($type, DnsRecord::TYPES, true)) {
                $records[] = new DnsRecord(trim((string) $host->name) ?: '@', $type, trim((string) $host->address), $type === 'MX' ? (int) $host->mxpref : null);
            }
        }

        return $records;
    }

    public function setDnsRecords(Domain $domain, array $records, array $credentials): void
    {
        $params = $this->names($domain);
        foreach (array_values($records) as $i => $record) {
            $n = $i + 1;
            $params["HostName{$n}"] = $record->host;
            $params["RecordType{$n}"] = $record->type;
            $params["Address{$n}"] = $record->value;
            if ($record->type === 'MX') {
                $params["MXPref{$n}"] = $record->priority ?? 10;
            }
        }

        $this->call('SetHosts', $params, $credentials);
    }

    /** @return array{SLD: string, TLD: string} */
    private function names(Domain $domain): array
    {
        return [self::SLD => $domain->sld(), self::TLD => $domain->extension()];
    }

    /** @param  list<string>  $nameservers */
    private function nameserverParams(array $nameservers): array
    {
        $params = [];
        foreach (array_values($nameservers) as $i => $nameserver) {
            $params['NS'.($i + 1)] = $nameserver;
        }

        return $params;
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
                "{$type}StateProvince" => $contact->state,
                "{$type}PostalCode" => $contact->postcode,
                "{$type}Country" => $contact->country,
                "{$type}EmailAddress" => $contact->email,
                "{$type}Phone" => $contact->registrarPhone(),
            ];
        }

        return $params;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  array<string, string|int>  $params */
    private function call(string $command, array $params, array $credentials): SimpleXMLElement
    {
        try {
            $response = Http::timeout(30)->asForm()->post(! empty($credentials['test_mode']) ? self::TEST : self::LIVE, [
                'uid' => $credentials['username'] ?? '',
                'pw' => $credentials['api_token'] ?? '',
                'command' => $command,
                'responsetype' => 'xml',
            ] + $params);
        } catch (ConnectionException $e) {
            throw new RegistrarError("Enom couldn't be reached: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RegistrarError("Enom answered HTTP {$response->status()} to {$command}.");
        }

        $xml = $this->xml($response->body(), self::NAME);

        if ((int) $this->text($xml, '/interface-response/ErrCount') > 0) {
            $errors = array_map(fn ($error) => trim((string) $error), $xml->xpath('/interface-response/errors/*') ?: []);

            throw new RegistrarError('Enom refused '.$command.': '.(implode(' ', array_filter($errors)) ?: 'no reason given'));
        }

        return $xml;
    }
}
