<?php

namespace Tests\Support;

use App\Domains\DnsRecord;
use App\Domains\DomainContact;
use App\Domains\DomainInfo;
use App\Domains\ManagesDns;
use App\Domains\Registrar;
use App\Domains\RegistrarError;
use App\Domains\RegistrarResult;
use App\Models\Domain;

/** A registrar that records what it was asked and answers as told. */
class FakeRegistrar implements ManagesDns, Registrar
{
    /** @var list<array{0: string, 1: string, 2: mixed}> method, domain name, argument */
    public array $calls = [];

    /** @var array<string, bool> */
    public array $taken = [];

    public ?RegistrarResult $result = null;

    public ?DomainInfo $info = null;

    /** @var array<string, DomainInfo|string> what info() answers for each name; a string is thrown as the registrar's error */
    public array $infoFor = [];

    public ?string $refuse = null;

    public ?string $eppCode = 'EPP-123';

    /** @var list<DnsRecord> */
    public array $records = [];

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake Registrar';
    }

    public function configFields(): array
    {
        return ['token' => ['label' => 'Token', 'secret' => true], 'sandbox' => ['label' => 'Sandbox', 'type' => 'checkbox']];
    }

    public function available(array $names, array $credentials): array
    {
        $this->call('available', implode(',', $names));

        return collect($names)->mapWithKeys(fn (string $name) => [$name => ! ($this->taken[$name] ?? false)])->all();
    }

    public function register(Domain $domain, array $credentials): RegistrarResult
    {
        $this->call('register', $domain->name, $domain->years);

        return $this->result ?? RegistrarResult::done(data: ['order_id' => 'R1']);
    }

    public function transfer(Domain $domain, array $credentials): RegistrarResult
    {
        $this->call('transfer', $domain->name, $domain->epp_code);

        return $this->result ?? RegistrarResult::inProgress(['order_id' => 'T1']);
    }

    public function renew(Domain $domain, int $years, array $credentials): RegistrarResult
    {
        $this->call('renew', $domain->name, $years);

        return $this->result ?? RegistrarResult::done();
    }

    public function info(Domain $domain, array $credentials): DomainInfo
    {
        $this->call('info', $domain->name);
        $answer = $this->infoFor[$domain->name] ?? null;
        if (is_string($answer)) {
            throw new RegistrarError($answer);
        }

        return $answer ?? $this->info ?? new DomainInfo(DomainInfo::ACTIVE, $domain->expiry_date?->toImmutable());
    }

    public function setNameservers(Domain $domain, array $nameservers, array $credentials): void
    {
        $this->call('setNameservers', $domain->name, $nameservers);
    }

    public function contact(Domain $domain, array $credentials): DomainContact
    {
        return $domain->registrant();
    }

    public function setContact(Domain $domain, DomainContact $contact, array $credentials): void
    {
        $this->call('setContact', $domain->name, $contact->toArray());
    }

    public function setIdProtection(Domain $domain, bool $on, array $credentials): void
    {
        $this->call('setIdProtection', $domain->name, $on);
    }

    public function setLock(Domain $domain, bool $locked, array $credentials): void
    {
        $this->call('setLock', $domain->name, $locked);
    }

    public function eppCode(Domain $domain, array $credentials): ?string
    {
        $this->call('eppCode', $domain->name);

        return $this->eppCode;
    }

    public function testConnection(array $credentials): void
    {
        $this->call('testConnection', '', $credentials);
    }

    public function dnsRecords(Domain $domain, array $credentials): array
    {
        return $this->records;
    }

    public function setDnsRecords(Domain $domain, array $records, array $credentials): void
    {
        $this->call('setDnsRecords', $domain->name, array_map(fn (DnsRecord $record) => $record->toArray(), $records));
        $this->records = $records;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_column($this->calls, 0);
    }

    private function call(string $method, string $name, mixed $argument = null): void
    {
        if ($this->refuse !== null) {
            throw new RegistrarError($this->refuse);
        }
        $this->calls[] = [$method, $name, $argument];
    }
}
