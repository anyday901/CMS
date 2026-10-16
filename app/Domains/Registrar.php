<?php

namespace App\Domains;

use App\Models\Domain;

/**
 * Registers and manages domains at a registrar. Register the class in
 * config/domains.php, enter its credentials under Settings > Domains, then
 * choose it on each TLD. $credentials are the values of configFields().
 *
 * Throw RegistrarError with a message staff can act on when the registrar
 * refuses a request or can't be reached.
 */
interface Registrar
{
    /** Stored on TLDs and domains, e.g. "enom". Keep it stable. */
    public function key(): string;

    public function label(): string;

    /**
     * Credentials asked for under Settings > Domains, keyed by name. "secret"
     * fields are saved encrypted and not shown again; "type" is text (the
     * default) or checkbox.
     *
     * @return array<string, array{label: string, secret?: bool, type?: string, help?: string}>
     */
    public function configFields(): array;

    /**
     * Whether each domain can be registered: true when free, false when taken.
     *
     * @param  list<string>  $names
     * @return array<string, bool>
     */
    public function available(array $names, array $credentials): array;

    /** Registers the domain for $domain->years, with its nameservers and registrant. */
    public function register(Domain $domain, array $credentials): RegistrarResult;

    /** Starts a transfer to us, with $domain->epp_code. Usually finishes days later; the sync picks that up. */
    public function transfer(Domain $domain, array $credentials): RegistrarResult;

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult;

    /** What the registrar knows: expiry date, status, nameservers, lock and ID protection. */
    public function info(Domain $domain, array $credentials): DomainInfo;

    /** @param  list<string>  $nameservers */
    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void;

    public function contact(Domain $domain, array $credentials): DomainContact;

    public function setContact(Domain $domain, DomainContact $contact, array $credentials): void;

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void;

    public function setLock(Domain $domain, bool $locked, array $credentials): void;

    /** The EPP (auth) code, or null when the registrar emails it to the registrant instead. */
    public function eppCode(Domain $domain, array $credentials): ?string;

    /** Makes one harmless call, throwing RegistrarError when the credentials don't work. */
    public function testConnection(array $credentials): void;
}
