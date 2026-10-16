<?php

namespace Tests\Feature\Domains;

use App\Domains\DomainName;
use App\Domains\Whois;
use App\Domains\WhoisSocket;
use App\Models\Tld;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class WhoisTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const RDAP = 'https://rdap.example-registry.test/';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            Whois::BOOTSTRAP_URL => Http::response(['services' => [[['com', 'net'], [self::RDAP]]]]),
            self::RDAP.'domain/taken.com' => Http::response([
                'ldhName' => 'TAKEN.COM',
                'status' => ['client transfer prohibited'],
                'events' => [['eventAction' => 'registration', 'eventDate' => '1999-03-15T05:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => '2027-03-15T04:00:00Z']],
                'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Example Registrar, Inc.']]]]],
                'nameservers' => [['ldhName' => 'NS1.EXAMPLE.NET'], ['ldhName' => 'NS2.EXAMPLE.NET']],
                'secureDNS' => ['delegationSigned' => false],
            ]),
            self::RDAP.'domain/*' => Http::response(['errorCode' => 404], 404),
        ]);
    }

    public function test_rdap_records_are_shown_and_a_404_means_available(): void
    {
        $result = app(Whois::class)->lookup('taken.com');

        $this->assertFalse($result->available);
        $this->assertSame('Example Registrar, Inc.', $result->fields['Registrar']);
        $this->assertSame('2027-03-15', $result->fields['Expires']);
        $this->assertSame('ns1.example.net, ns2.example.net', $result->fields['Nameservers']);
        $this->assertSame('Unsigned', $result->fields['DNSSEC']);
        $this->assertTrue(app(Whois::class)->lookup('free-name.com')->available);
    }

    public function test_the_store_whois_page(): void
    {
        $this->get(route('domains.whois', ['domain' => 'Taken.com']))->assertOk()->assertSee('Example Registrar, Inc.')->assertSee('ns1.example.net');
        $this->get(route('domains.whois', ['domain' => 'not a domain']))->assertOk()->assertSee("isn't a domain name");
    }

    public function test_the_search_uses_whois_when_no_registrar_is_set_up(): void
    {
        $this->tld('com', ['registrar' => null]);

        $this->get(route('domains.index', ['domain' => 'taken.com']))->assertOk()->assertSee('Taken');
        $this->get(route('domains.index', ['domain' => 'free-name.com']))->assertOk()->assertSee('Available');
    }

    public function test_extensions_without_rdap_fall_back_to_their_whois_server(): void
    {
        $socket = new class extends WhoisSocket
        {
            public array $asked = [];

            public function query(string $server, string $name): string
            {
                $this->asked[] = [$server, $name];

                return $name === 'free.io' ? "NOT FOUND\n" : "Domain Name: taken.io\nRegistrar: Someone\n";
            }
        };
        $this->app->instance(WhoisSocket::class, $socket);
        $tld = Tld::create(['extension' => 'io', 'whois_server' => 'whois.nic.io', 'whois_available' => 'not found']);

        $this->assertTrue(app(Whois::class)->lookup('free.io', $tld)->available);
        $taken = app(Whois::class)->lookup('taken.io', $tld);
        $this->assertFalse($taken->available);
        $this->assertStringContainsString('Registrar: Someone', $taken->text);
        $this->assertSame([['whois.nic.io', 'free.io'], ['whois.nic.io', 'taken.io']], $socket->asked);
        $this->assertNull(app(Whois::class)->lookup('nowhere.zz')->available);
    }

    public function test_names_are_tidied_and_matched_to_the_longest_extension(): void
    {
        $this->assertSame('example.com', DomainName::normalise('  HTTPS://www.Example.com/path '));
        $this->assertSame('xn--bcher-kva.de', DomainName::normalise('bücher.de'));
        $this->assertNull(DomainName::normalise('-bad-.com'));
        $this->assertNull(DomainName::normalise('under_score.com'));

        $tlds = collect([Tld::create(['extension' => 'uk']), Tld::create(['extension' => 'co.uk'])]);
        $parsed = DomainName::parse('shop.co.uk', $tlds);
        $this->assertSame(['shop', 'co.uk'], [$parsed->sld, $parsed->tld->extension]);
        $this->assertNull(DomainName::parse('shop.org', $tlds));
    }
}
