<?php

namespace Tests\Feature\Domains;

use App\Auth\PortalContact;
use App\Domains\DnsRecord;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Tld;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class DomainPortalTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const NAME = 'example.com';

    private Client $client;

    private Tld $com;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRegistrar();
        $this->com = $this->tld();
        $this->client = $this->registrant();
        $this->domain = Domain::factory()->for($this->client)->create([
            'tld_id' => $this->com->id, 'registrar' => 'fake', 'name' => self::NAME, 'nameservers' => ['ns1.host.test', 'ns2.host.test'],
        ]);
        $this->actingAs($this->client, 'client');
    }

    public function test_clients_see_their_domains_and_dns_records(): void
    {
        $this->registrar->records = [new DnsRecord('www', 'CNAME', 'example.com')];
        Domain::factory()->create(['name' => 'someone-else.com']);

        $this->get(route('portal.domains.index'))->assertOk()->assertSee(self::NAME)->assertDontSee('someone-else.com');
        $this->get(route('portal.domains.show', $this->domain))->assertOk()->assertSee('ns1.host.test')->assertSee('value="www"', false);
    }

    public function test_other_clients_domains_are_not_found(): void
    {
        $other = Domain::factory()->create();

        $this->get(route('portal.domains.show', $other))->assertNotFound();
        $this->put(route('portal.domains.nameservers', $other), ['nameservers' => ['ns1.a.test', 'ns2.a.test']])->assertNotFound();
    }

    public function test_nameservers_are_changed_at_the_registrar(): void
    {
        $this->put(route('portal.domains.nameservers', $this->domain), ['nameservers' => ['NS1.Example.net.', 'ns2.example.net', '', 'ns1.example.net']])
            ->assertSessionHasNoErrors();

        $this->assertSame([['setNameservers', self::NAME, ['ns1.example.net', 'ns2.example.net']]], $this->registrar->calls);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $this->domain->refresh()->nameservers);

        $this->put(route('portal.domains.nameservers', $this->domain), ['nameservers' => ['ns1.example.net']])
            ->assertSessionHasErrors(['nameservers' => 'Enter at least two nameservers.']);
    }

    public function test_registrar_refusals_are_shown(): void
    {
        $this->registrar->refuse = 'Nameserver does not exist';

        $this->put(route('portal.domains.nameservers', $this->domain), ['nameservers' => ['ns1.nowhere.test', 'ns2.nowhere.test']])
            ->assertSessionHasErrors(['nameservers' => "The registrar didn't accept the change: Nameserver does not exist"]);
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $this->domain->refresh()->nameservers);
    }

    public function test_dns_records_are_saved_and_empty_rows_dropped(): void
    {
        $this->put(route('portal.domains.dns', $this->domain), ['records' => [
            ['host' => '@', 'type' => 'A', 'value' => '192.0.2.1'],
            ['host' => '', 'type' => 'MX', 'value' => 'mail.example.com', 'priority' => '5'],
            ['host' => 'old', 'type' => 'TXT', 'value' => ''],
        ]])->assertSessionHasNoErrors();

        [$method, , $records] = $this->registrar->calls[0];
        $this->assertSame('setDnsRecords', $method);
        $this->assertCount(2, $records);
        $this->assertSame(['host' => '@', 'type' => 'MX', 'value' => 'mail.example.com', 'priority' => 5, 'ttl' => 3600], $records[1]);
    }

    public function test_bad_dns_values_are_refused(): void
    {
        $this->put(route('portal.domains.dns', $this->domain), ['records' => [['host' => '@', 'type' => 'A', 'value' => 'not-an-ip']]])
            ->assertSessionHasErrors(['dns' => "not-an-ip isn't a valid A record value."]);
        $this->assertSame([], $this->registrar->calls);
    }

    public function test_dns_needs_the_tld_to_offer_it(): void
    {
        $this->com->update(['dns_management' => false]);

        $this->put(route('portal.domains.dns', $this->domain), ['records' => []])->assertNotFound();
    }

    public function test_the_registrant_is_changed_and_kept(): void
    {
        $contact = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.org', 'phone' => '+44 20 7946 0000', 'address1' => '1 High St', 'city' => 'London', 'postcode' => 'N1 1AA', 'country' => 'gb'];

        $this->put(route('portal.domains.contact', $this->domain), $contact)->assertSessionHasNoErrors();

        $this->assertSame('setContact', $this->registrar->calls[0][0]);
        $this->assertSame('GB', $this->domain->refresh()->registrant()->country);
        $this->assertSame('Ada', $this->domain->contact['first_name']);
    }

    public function test_id_protection_changes_the_renewal_price(): void
    {
        $this->put(route('portal.domains.id-protection', $this->domain), ['id_protection' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->domain->refresh()->id_protection);
        $this->assertSame(1800, $this->domain->recurring_amount);

        $this->put(route('portal.domains.id-protection', $this->domain), ['id_protection' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(1500, $this->domain->refresh()->recurring_amount);

        $this->com->update(['id_protection' => false]);
        $this->put(route('portal.domains.id-protection', $this->domain), ['id_protection' => '1'])
            ->assertSessionHasErrors(['id_protection' => "ID protection isn't offered for example.com."]);
    }

    public function test_the_lock_and_epp_code(): void
    {
        $this->put(route('portal.domains.lock', $this->domain), ['locked' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($this->domain->refresh()->locked);

        $this->post(route('portal.domains.epp-code', $this->domain))->assertSessionHas('epp_code', 'EPP-123');

        $this->registrar->eppCode = null;
        $this->post(route('portal.domains.epp-code', $this->domain))->assertSessionHas('status', "The EPP code has been emailed to the domain's registrant.");
    }

    public function test_auto_renew_is_turned_off_and_on(): void
    {
        $this->put(route('portal.domains.auto-renew', $this->domain), ['auto_renew' => '0'])->assertRedirect();
        $this->assertFalse($this->domain->refresh()->auto_renew);
    }

    public function test_domains_not_yet_registered_cant_be_managed(): void
    {
        $this->domain->update(['status' => DomainStatus::Pending]);

        $this->put(route('portal.domains.lock', $this->domain), ['locked' => '0'])->assertNotFound();
        $this->get(route('portal.domains.show', $this->domain))->assertOk();
    }

    public function test_contacts_need_the_services_permission(): void
    {
        $contact = $this->client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.org', 'portal_access' => true, 'permissions' => ['billing']]);
        PortalContact::start($contact);

        $this->get(route('portal.domains.index'))->assertRedirect('/portal');
        $this->post(route('portal.domains.renew', $this->domain), ['years' => 1])->assertRedirect('/portal');
    }
}
