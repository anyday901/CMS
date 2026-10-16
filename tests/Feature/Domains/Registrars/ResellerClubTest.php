<?php

namespace Tests\Feature\Domains\Registrars;

use App\Domains\DnsRecord;
use App\Domains\DomainInfo;
use App\Domains\RegistrarError;
use App\Domains\Registrars\ResellerClub;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class ResellerClubTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const API = 'https://test.httpapi.com/api/';

    private const CREDENTIALS = ['reseller_id' => '12345', 'api_key' => 'key-1', 'test_mode' => '1'];

    private const DETAILS = 'domains/details-by-name.json*';

    private ResellerClub $club;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->club = new ResellerClub;
    }

    /** @param  array<string, mixed>  $replies  JSON (or a bare value) by API path pattern */
    private function fake(array $replies): void
    {
        Http::fake(collect($replies)->mapWithKeys(fn ($reply, string $path) => [self::API.$path => is_object($reply) ? $reply : Http::response($reply)])->all());
    }

    private function sent(string $path, string $part): void
    {
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::API.$path)
            && str_contains($request->method() === 'GET' ? (string) parse_url($request->url(), PHP_URL_QUERY) : $request->body(), $part));
    }

    public function test_availability_repeats_the_tlds_parameter(): void
    {
        $this->fake(['domains/available.json*' => ['example.com' => ['status' => 'available'], 'example.net' => ['status' => 'regthroughothers']]]);

        $this->assertSame(['example.com' => true, 'example.net' => false], $this->club->available(['example.com', 'example.net'], self::CREDENTIALS));

        $this->sent('domains/available.json', 'auth-userid=12345&api-key=key-1&domain-name=example&tlds=com&tlds=net');
    }

    public function test_registration_finds_or_makes_the_customer_and_a_contact(): void
    {
        $this->fake([
            'customers/details.json*' => Http::response(['status' => 'ERROR', 'message' => 'Customer not found'], 500),
            'customers/v2/signup.json' => '777',
            'contacts/add.json' => '888',
            'domains/register.json' => ['entityid' => '999', 'actionstatus' => 'Success'],
        ]);
        $domain = $this->moduleDomain('resellerclub', attributes: ['years' => 2]);

        $result = $this->club->register($domain, self::CREDENTIALS);

        $this->assertTrue($result->ok);
        $this->assertSame(['customer_id' => '777', 'contact_id' => '888', 'order_id' => '999'], $result->data);
        $this->assertSame('888', $domain->refresh()->registrarValue('contact_id'));
        $this->sent('customers/v2/signup.json', 'phone-cc=1&phone=5550100');
        $this->sent('contacts/add.json', 'customer-id=777&type=Contact');
        $this->sent('domains/register.json', 'years=2&ns=ns1.host.test&ns=ns2.host.test&invoice-option=NoInvoice');
        $this->sent('domains/register.json', 'reg-contact-id=888&admin-contact-id=888');
    }

    public function test_a_failed_order_is_reported(): void
    {
        $this->fake([
            'domains/register.json' => ['actionstatus' => 'Failed', 'actionstatusdesc' => 'Insufficient funds'],
        ]);
        $domain = $this->moduleDomain('resellerclub', attributes: ['registrar_data' => ['customer_id' => '7', 'contact_id' => '8']]);

        $result = $this->club->register($domain, self::CREDENTIALS);

        $this->assertFalse($result->ok);
        $this->assertSame('ResellerClub refused it: Insufficient funds', $result->message);
    }

    public function test_api_errors_carry_the_reason(): void
    {
        $this->fake([self::DETAILS => Http::response(['status' => 'ERROR', 'message' => 'Invalid API key'], 500)]);

        $this->expectException(RegistrarError::class);
        $this->expectExceptionMessage('ResellerClub refused domains/details-by-name.json: Invalid API key');
        $this->club->eppCode($this->moduleDomain('resellerclub'), self::CREDENTIALS);
    }

    public function test_info_reads_the_order_details(): void
    {
        $this->fake([self::DETAILS => [
            'orderid' => '999', 'endtime' => (string) CarbonImmutable::parse('2027-03-15 12:00', 'UTC')->timestamp, 'currentstatus' => 'Active',
            'orderstatus' => ['transferlock'], 'ns1' => 'NS1.HOST.TEST', 'ns2' => 'ns2.host.test', 'isprivacyprotected' => 'true', 'domsecret' => 'SECRET',
        ]]);
        $domain = $this->moduleDomain('resellerclub');

        $info = $this->club->info($domain, self::CREDENTIALS);

        $this->assertSame(DomainInfo::ACTIVE, $info->status);
        $this->assertSame('2027-03-15', $info->expiry->toDateString());
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $info->nameservers);
        $this->assertTrue($info->locked);
        $this->assertTrue($info->idProtection);
        $this->assertSame('999', $domain->registrarValue('order_id'));
        $this->assertSame('SECRET', $this->club->eppCode($domain, self::CREDENTIALS));
    }

    public function test_renewal_sends_the_current_expiry(): void
    {
        $end = CarbonImmutable::parse('2027-03-15', 'UTC')->timestamp;
        $this->fake([self::DETAILS => ['orderid' => '999', 'endtime' => (string) $end], 'domains/renew.json' => ['entityid' => '999', 'actionstatus' => 'Success']]);

        $result = $this->club->renew($this->moduleDomain('resellerclub'), 2, self::CREDENTIALS);

        $this->assertSame('2029-03-15', $result->expiry->toDateString());
        $this->sent('domains/renew.json', "order-id=999&years=2&exp-date={$end}");
    }

    public function test_dns_changes_are_applied_as_a_difference(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'search-records.json') && str_contains($url, 'type=A&') => Http::response(['recsonpage' => '2', '1' => ['host' => 'example.com', 'value' => '192.0.2.1', 'timetolive' => '3600'], '2' => ['host' => 'old.example.com', 'value' => '192.0.2.9', 'timetolive' => '3600']]),
                str_contains($url, 'search-records.json') => Http::response(['recsonpage' => '0']),
                default => Http::response(['status' => 'Success']),
            };
        });
        $domain = $this->moduleDomain('resellerclub', attributes: ['registrar_data' => ['order_id' => '999']]);

        $this->club->setDnsRecords($domain, [new DnsRecord('@', 'A', '192.0.2.1'), new DnsRecord('www', 'CNAME', 'example.com')], self::CREDENTIALS);

        $this->sent('dns/activate.json', 'order-id=999');
        $this->sent('dns/manage/delete-ipv4-record.json', 'host=old&value=192.0.2.9');
        $this->sent('dns/manage/add-cname-record.json', 'host=www&value=example.com&ttl=3600');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'add-ipv4-record'));
    }

    public function test_management_calls(): void
    {
        $this->fake(['*' => ['status' => 'Success']]);
        $domain = $this->moduleDomain('resellerclub', attributes: ['registrar_data' => ['order_id' => '999']]);

        $this->club->setNameservers($domain, ['ns1.a.test', 'ns2.a.test'], self::CREDENTIALS);
        $this->club->setLock($domain, false, self::CREDENTIALS);
        $this->club->setIdProtection($domain, true, self::CREDENTIALS);

        $this->sent('domains/modify-ns.json', 'order-id=999&ns=ns1.a.test&ns=ns2.a.test');
        $this->sent('domains/disable-theft-protection.json', 'order-id=999');
        $this->sent('domains/modify-privacy-protection.json', 'protect-privacy=true');
    }
}
