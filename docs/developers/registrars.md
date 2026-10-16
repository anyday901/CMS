# Registrar modules

A registrar module registers, transfers, renews and manages domains at one registrar. Staff choose a registrar on each TLD under **Settings > TLDs and pricing** and enter its credentials under **Settings > Registrars and domain settings**; every domain of that TLD then goes through the module.

The code is in `app/Domains`, and the built-in modules in `app/Domains/Registrars`:

| Module | Key | Class | API |
|---|---|---|---|
| Enom | `enom` | `Enom` | `interface.asp` with form posts and XML replies |
| Namecheap | `namecheap` | `Namecheap` | `xml.response` with form posts and XML replies |
| OpenSRS | `opensrs` | `OpenSrs` | XCP over HTTPS: OPS XML envelopes signed with the API key |
| ResellerClub | `resellerclub` | `ResellerClub` | LogicBoxes HTTP API with JSON replies |

All four use Laravel's `Http` client, so tests fake their calls with `Http::fake()` (see `tests/Feature/Domains/Registrars`). They haven't been tried against live registrar accounts yet.

## The `Registrar` interface

```php
namespace App\Domains;

use App\Models\Domain;

interface Registrar
{
    /** Stored on TLDs and domains, e.g. "enom". Keep it stable. */
    public function key(): string;

    public function label(): string;

    /** Credentials asked for under Settings > Domains. */
    public function configFields(): array;

    /** @return array<string, bool> true when free, false when taken, keyed by name */
    public function available(array $names, array $credentials): array;

    public function register(Domain $domain, array $credentials): RegistrarResult;

    public function transfer(Domain $domain, array $credentials): RegistrarResult;

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult;

    public function info(Domain $domain, array $credentials): DomainInfo;

    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void;

    public function contact(Domain $domain, array $credentials): DomainContact;

    public function setContact(Domain $domain, DomainContact $contact, array $credentials): void;

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void;

    public function setLock(Domain $domain, bool $locked, array $credentials): void;

    /** The EPP code, or null when the registrar emails it to the registrant instead. */
    public function eppCode(Domain $domain, array $credentials): ?string;

    public function testConnection(array $credentials): void;
}
```

- **`configFields()`** returns fields keyed by name, each with a `label`, and optionally `secret` (saved encrypted, not shown again), `type` (`text` or `checkbox`) and `help`. `$credentials` holds their saved values.
- **`register()`**, **`transfer()`** and **`renew()`** return a `RegistrarResult`:
    - `RegistrarResult::done($expiry, $data)` when it's done. `$expiry` is the new expiry date if the registrar says it; otherwise CMS works it out.
    - `RegistrarResult::inProgress($data)` when the registrar finishes it later, as with most transfers. The domain shows as **Transfer in progress** until `info()` reports it active.
    - `RegistrarResult::failed($message)` when it was refused.
    - `$data` is kept on the domain (`registrar_data`) for later calls; read it with `$domain->registrarValue('order_id')`.
- **`info()`** returns a `DomainInfo` with a status (`DomainInfo::ACTIVE`, `EXPIRED`, `PENDING_TRANSFER`, or `GONE` when the domain is no longer in your account), the expiry date, nameservers, lock and ID protection. Leave out what the registrar doesn't say (null or an empty list); the nightly sync keeps what it knows.
- **Throw `App\Domains\RegistrarError`** with a message staff (and clients, for portal changes) can act on when the registrar refuses a request or can't be reached.

Each domain gives you `$domain->name`, `sld()` and `extension()` (`shop` and `co.uk` for `shop.co.uk`), `years`, `nameservers`, `epp_code`, `id_protection`, and `registrant()`, a `DomainContact` with the registrant's details. `DomainContact::registrarPhone()` writes the phone number as `+CC.NUMBER`, the form most registrars want.

## DNS records

A module whose registrar hosts DNS for its domains also implements `App\Domains\ManagesDns`:

```php
interface ManagesDns
{
    /** @return list<DnsRecord> */
    public function dnsRecords(Domain $domain, array $credentials): array;

    /** Replaces the domain's records with these. */
    public function setDnsRecords(Domain $domain, array $records, array $credentials): void;
}
```

A `DnsRecord` has a `host` (`@` for the domain itself), a `type` (A, AAAA, CNAME, MX or TXT), a `value`, an MX `priority` and a `ttl`. Clients get the DNS editor in the portal when the module implements this and the TLD has **DNS management** ticked.

## Registering a module

Add the class to `config/domains.php`:

```php
'registrars' => [
    Enom::class,
    Namecheap::class,
    OpenSrs::class,
    ResellerClub::class,
    App\Domains\Registrars\MyRegistrar::class,
],
```

An add-on can do the same from its service provider by pushing onto `domains.registrars`.

## Testing

Fake the registrar's HTTP calls and call the module directly:

```php
Http::fake(['api.example-registrar.test/*' => Http::response(['status' => 'available'])]);

$this->assertSame(['example.com' => true], (new MyRegistrar)->available(['example.com'], ['api_key' => 'test']));
```

For tests of the ordering and renewal flow, `tests/Support/FakeRegistrar.php` is a module that records what it was asked and answers as told.
