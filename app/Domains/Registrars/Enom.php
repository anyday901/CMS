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

/** Enom's reseller API (interface.asp), with XML replies. */
class Enom implements ManagesDns, Registrar
{
    use XmlReplies;

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
            $xml = EnomApi::call('Check', ['DomainList' => implode(',', $chunk)], $credentials);
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
        $xml = EnomApi::call('Purchase', $this->names($domain) + [
            'NumYears' => $domain->years,
            'IgnoreNSFail' => 'Yes',
        ] + EnomApi::nameservers($domain->nameservers ?? []) + EnomApi::contacts($domain->registrant(), self::CONTACT_TYPES), $credentials);

        $result = RegistrarResult::done(data: ['order_id' => $this->text($xml, '/interface-response/OrderID')]);
        if ($domain->id_protection) {
            $this->setIdProtection($domain, true, $credentials);
        }

        return $result;
    }

    public function transfer(Domain $domain, array $credentials): RegistrarResult
    {
        $xml = EnomApi::call('TP_CreateOrder', [
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
        $xml = EnomApi::call('Extend', $this->names($domain) + ['NumYears' => $years], $credentials);

        return RegistrarResult::done(data: ['order_id' => $this->text($xml, '/interface-response/OrderID')]);
    }

    public function info(Domain $domain, array $credentials): DomainInfo
    {
        try {
            $xml = EnomApi::call('GetDomainInfo', $this->names($domain), $credentials);
        } catch (RegistrarError $e) {
            if (preg_match('/not found|not in your account|doesn\'t belong|does not belong/i', $e->getMessage())) {
                return new DomainInfo($domain->status->value === 'pending_transfer' ? DomainInfo::PENDING_TRANSFER : DomainInfo::GONE);
            }
            throw $e;
        }

        $expiry = EnomApi::date($this->text($xml, '//GetDomainInfo/status/expiration'));
        $state = strtolower((string) $this->text($xml, '//GetDomainInfo/status/registrationstatus'));
        $nameservers = array_map(fn ($ns) => strtolower(trim((string) $ns)), $xml->xpath("//services/entry[@name='dnsserver']//dns") ?: []);

        return new DomainInfo(
            match (true) {
                str_contains($state, 'expired') => DomainInfo::EXPIRED,
                $expiry !== null && $expiry->lt(today()) => DomainInfo::EXPIRED,
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
            $value = $this->text(EnomApi::call('GetRegLock', $this->names($domain), $credentials), '/interface-response/reg-lock');
        } catch (RegistrarError) {
            return null;
        }

        return $value === null ? null : $value === '1';
    }

    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void
    {
        EnomApi::call('ModifyNS', $this->names($domain) + EnomApi::nameservers($nameservers), $credentials);
    }

    public function contact(Domain $domain, array $credentials): DomainContact
    {
        $xml = EnomApi::call('GetContacts', $this->names($domain), $credentials);
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
        EnomApi::call('Contacts', $this->names($domain) + ['ContactType' => 'REGISTRANT'] + EnomApi::contacts($contact, ['Registrant']), $credentials);
    }

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void
    {
        EnomApi::call($on ? 'EnableServices' : 'DisableServices', $this->names($domain) + ['Service' => 'WPPS'], $credentials);
    }

    public function setLock(Domain $domain, bool $locked, array $credentials): void
    {
        EnomApi::call('SetRegLock', $this->names($domain) + ['UnlockRegistrar' => $locked ? 0 : 1], $credentials);
    }

    public function eppCode(Domain $domain, array $credentials): ?string
    {
        // Enom resets the code and emails it to the registrant rather than returning it.
        EnomApi::call('SynchAuthInfo', $this->names($domain) + ['EmailEPP' => 'True', 'RunSynchAutoInfo' => 'True'], $credentials);

        return null;
    }

    public function testConnection(array $credentials): void
    {
        EnomApi::call('GetBalance', [], $credentials);
    }

    public function dnsRecords(Domain $domain, array $credentials): array
    {
        $xml = EnomApi::call('GetHosts', $this->names($domain), $credentials);
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

        EnomApi::call('SetHosts', $params, $credentials);
    }

    /** @return array{SLD: string, TLD: string} */
    private function names(Domain $domain): array
    {
        return [self::SLD => $domain->sld(), self::TLD => $domain->extension()];
    }
}
