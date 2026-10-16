<?php

namespace App\Domains;

use App\Models\Activity;
use App\Models\Domain;
use InvalidArgumentException;

/**
 * Changes clients and staff make to a registered domain: nameservers, DNS
 * records, the registrant, ID protection and the transfer lock. Each goes to
 * the registrar straight away and is saved here once it accepts.
 */
class DomainManager
{
    public const MIN_NAMESERVERS = 2;

    public const MAX_NAMESERVERS = 5;

    public function __construct(private RegistrarRegistry $registrars) {}

    /**
     * @param  list<string>  $nameservers
     *
     * @throws RegistrarError|InvalidArgumentException
     */
    public function setNameservers(Domain $domain, array $nameservers): void
    {
        $nameservers = array_values(array_unique(array_filter(array_map(fn ($ns) => strtolower(rtrim(trim((string) $ns), '.')), $nameservers))));

        if (count($nameservers) < self::MIN_NAMESERVERS) {
            throw new InvalidArgumentException(__('Enter at least two nameservers.'));
        }
        foreach ($nameservers as $nameserver) {
            if (! DomainName::valid($nameserver)) {
                throw new InvalidArgumentException(__(':nameserver isn\'t a nameserver name, such as ns1.example.com.', ['nameserver' => $nameserver]));
            }
        }

        [$registrar, $credentials] = $this->registrars->forDomain($domain);
        $registrar->setNameservers($domain, $nameservers, $credentials);
        $domain->forceFill(['nameservers' => $nameservers])->save();
        Activity::record("Changed the nameservers of {$domain->name} to ".implode(', ', $nameservers), $domain);
    }

    /** Whether the domain's registrar hosts DNS records for it. */
    public function hasDns(Domain $domain): bool
    {
        return $this->registrars->find($domain->registrar) instanceof ManagesDns
            && (bool) $domain->tld?->dns_management;
    }

    /**
     * @return list<DnsRecord>
     *
     * @throws RegistrarError
     */
    public function dnsRecords(Domain $domain): array
    {
        [$registrar, $credentials] = $this->dnsRegistrar($domain);

        return $registrar->dnsRecords($domain, $credentials);
    }

    /**
     * @param  list<DnsRecord>  $records
     *
     * @throws RegistrarError
     */
    public function setDnsRecords(Domain $domain, array $records): void
    {
        [$registrar, $credentials] = $this->dnsRegistrar($domain);
        $registrar->setDnsRecords($domain, $records, $credentials);
        Activity::record("Changed the DNS records of {$domain->name} (".count($records).' record'.(count($records) === 1 ? '' : 's').')', $domain);
    }

    /** @throws RegistrarError */
    public function setContact(Domain $domain, DomainContact $contact): void
    {
        [$registrar, $credentials] = $this->registrars->forDomain($domain);
        $registrar->setContact($domain, $contact, $credentials);
        $domain->forceFill(['contact' => $contact->toArray()])->save();
        Activity::record("Changed the registrant of {$domain->name}", $domain);
    }

    /**
     * Turns ID protection on or off. A priced protection is added to (or
     * taken off) the renewal price from the next renewal.
     *
     * @throws RegistrarError|InvalidArgumentException
     */
    public function setIdProtection(Domain $domain, bool $on): void
    {
        if ($on && ! $domain->tld?->id_protection) {
            throw new InvalidArgumentException(__('ID protection isn\'t offered for :name.', ['name' => $domain->name]));
        }
        if ($domain->id_protection === $on) {
            return;
        }

        [$registrar, $credentials] = $this->registrars->forDomain($domain);
        $registrar->setIdProtection($domain, $on, $credentials);
        $price = $domain->tld ? DomainOrders::protectionAmount($domain->tld->loadMissing('prices'), $domain->client->currency, $domain->years) : 0;
        $domain->forceFill([
            'id_protection' => $on,
            'recurring_amount' => max(0, $domain->recurring_amount + ($on ? $price : -$price)),
        ])->save();
        Activity::record(($on ? 'Turned on' : 'Turned off')." ID protection for {$domain->name}", $domain);
    }

    /** @throws RegistrarError */
    public function setLock(Domain $domain, bool $locked): void
    {
        [$registrar, $credentials] = $this->registrars->forDomain($domain);
        $registrar->setLock($domain, $locked, $credentials);
        $domain->forceFill(['locked' => $locked])->save();
        Activity::record(($locked ? 'Locked' : 'Unlocked')." {$domain->name} against transfers", $domain);
    }

    /**
     * The EPP code, or null when the registrar emailed it to the registrant.
     *
     * @throws RegistrarError
     */
    public function eppCode(Domain $domain): ?string
    {
        [$registrar, $credentials] = $this->registrars->forDomain($domain);
        $code = $registrar->eppCode($domain, $credentials);
        Activity::record("Asked for the EPP code of {$domain->name}", $domain);

        return $code;
    }

    /**
     * @return array{0: Registrar&ManagesDns, 1: array<string, string>}
     *
     * @throws RegistrarError
     */
    private function dnsRegistrar(Domain $domain): array
    {
        [$registrar, $credentials] = $this->registrars->forDomain($domain);

        if (! $registrar instanceof ManagesDns || ! $domain->tld?->dns_management) {
            throw new RegistrarError("DNS management isn't available for {$domain->name}.");
        }

        return [$registrar, $credentials];
    }
}
