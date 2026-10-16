<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\Domain;
use App\Models\Tld;

/** A fake registrar wired in, and helpers for TLDs and clients with a full registrant address. */
trait DomainSetup
{
    protected FakeRegistrar $registrar;

    protected function fakeRegistrar(): FakeRegistrar
    {
        $this->registrar = new FakeRegistrar;
        $this->app->instance(FakeRegistrar::class, $this->registrar);
        config([
            'domains.registrars' => [FakeRegistrar::class],
            'domains.credentials' => ['fake' => ['token' => 'secret-token']],
            'domains.nameservers' => ['ns1.host.test', 'ns2.host.test'],
        ]);

        return $this->registrar;
    }

    /** A TLD at the fake registrar with 1 and 2 year USD prices: register, transfer, renew, ID protection. */
    protected function tld(string $extension = 'com', array $attributes = [], int $register = 1200, int $renew = 1500, int $protection = 300): Tld
    {
        $tld = Tld::create(['extension' => $extension, 'registrar' => 'fake', 'id_protection' => true, 'dns_management' => true, ...$attributes]);
        $tld->prices()->create(['currency' => 'USD', 'years' => 1, 'register' => $register, 'transfer' => 900, 'renew' => $renew, 'id_protection' => $protection]);
        $tld->prices()->create(['currency' => 'USD', 'years' => 2, 'register' => $register * 2, 'transfer' => null, 'renew' => $renew * 2, 'id_protection' => $protection * 2]);

        return $tld;
    }

    /** A registered domain at $registrar, with a full registrant, for exercising a registrar module. */
    protected function moduleDomain(string $registrar, string $name = 'example.com', array $attributes = []): Domain
    {
        $extension = substr($name, strpos($name, '.') + 1);
        $tld = Tld::firstOrCreate(['extension' => $extension], ['registrar' => $registrar]);

        return Domain::factory()->for($this->registrant())->create([
            'name' => $name, 'tld_id' => $tld->id, 'registrar' => $registrar, 'nameservers' => ['ns1.host.test', 'ns2.host.test'], ...$attributes,
        ]);
    }

    protected function registrant(array $attributes = []): Client
    {
        return Client::factory()->create([
            'phone' => '555 0100', 'address1' => '1 Main St', 'city' => 'Springfield', 'postcode' => '12345', 'country' => 'US', 'state' => 'IL',
            ...$attributes,
        ]);
    }
}
