<?php

namespace Tests\Feature\Domains\Registrars;

use App\Domains\DnsRecord;
use App\Domains\DomainInfo;
use App\Domains\RegistrarError;
use App\Domains\Registrars\Namecheap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class NamecheapTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';

    private const CREDENTIALS = ['api_user' => 'reseller', 'api_key' => 'key-1', 'client_ip' => '203.0.113.5', 'sandbox' => '1'];

    private const COMMAND = 'Command';

    private Namecheap $namecheap;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->namecheap = new Namecheap;
    }

    /** @param  array<string, string>  $replies  CommandResponse bodies by command */
    private function fake(array $replies): void
    {
        Http::fake(function (Request $request) use ($replies) {
            $body = $replies[$request[self::COMMAND]] ?? '';

            return Http::response('<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><CommandResponse>'.$body.'</CommandResponse></ApiResponse>');
        });
    }

    private function refuse(string $number, string $reason): void
    {
        Http::fake(fn () => Http::response('<?xml version="1.0"?><ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="'.$number.'">'.$reason.'</Error></Errors></ApiResponse>'));
    }

    public function test_availability_treats_premium_names_as_taken(): void
    {
        $this->fake(['namecheap.domains.check' => '<DomainCheckResult Domain="free.com" Available="true" IsPremiumName="false" />'
            .'<DomainCheckResult Domain="taken.com" Available="false" IsPremiumName="false" />'
            .'<DomainCheckResult Domain="pricey.com" Available="true" IsPremiumName="true" />']);

        $this->assertSame(['free.com' => true, 'taken.com' => false, 'pricey.com' => false], $this->namecheap->available(['free.com', 'taken.com', 'pricey.com'], self::CREDENTIALS));

        Http::assertSent(fn (Request $request) => $request->url() === self::SANDBOX
            && $request['ApiUser'] === 'reseller' && $request['UserName'] === 'reseller' && $request['ApiKey'] === 'key-1' && $request['ClientIp'] === '203.0.113.5');
    }

    public function test_registration_sends_nameservers_whoisguard_and_contacts(): void
    {
        $this->fake(['namecheap.domains.create' => '<DomainCreateResult Domain="example.com" Registered="true" DomainID="9001" />']);

        $result = $this->namecheap->register($this->moduleDomain('namecheap', attributes: ['years' => 2, 'id_protection' => true]), self::CREDENTIALS);

        $this->assertTrue($result->ok);
        $this->assertSame('9001', $result->data['domain_id']);
        Http::assertSent(fn (Request $request) => $request['DomainName'] === 'example.com' && (int) $request['Years'] === 2
            && $request['Nameservers'] === 'ns1.host.test,ns2.host.test' && $request['WGEnabled'] === 'yes'
            && $request['RegistrantPostalCode'] === '12345' && $request['AdminPhone'] === '+1.5550100');
    }

    public function test_errors_carry_namecheaps_reason(): void
    {
        $this->refuse('2033409', 'Possible duplicate order');

        $this->expectException(RegistrarError::class);
        $this->expectExceptionMessage('Namecheap refused namecheap.domains.create: Possible duplicate order');
        $this->namecheap->register($this->moduleDomain('namecheap'), self::CREDENTIALS);
    }

    public function test_transfers_send_the_epp_code(): void
    {
        $this->fake(['namecheap.domains.transfer.create' => '<DomainTransferCreateResult DomainName="example.com" Transfer="true" TransferID="55" />']);

        $result = $this->namecheap->transfer($this->moduleDomain('namecheap', attributes: ['epp_code' => 'AUTH']), self::CREDENTIALS);

        $this->assertTrue($result->inProgress);
        Http::assertSent(fn (Request $request) => $request['EPPCode'] === 'AUTH');
    }

    public function test_info_reads_expiry_nameservers_and_whoisguard(): void
    {
        $this->fake(['namecheap.domains.getInfo' => '<DomainGetInfoResult Status="Ok" DomainName="example.com"><DomainDetails><ExpiredDate>03/15/2027</ExpiredDate></DomainDetails>'
            .'<Whoisguard Enabled="True"><ID>4242</ID></Whoisguard><DnsDetails ProviderType="CUSTOM"><Nameserver>NS1.HOST.TEST</Nameserver><Nameserver>ns2.host.test</Nameserver></DnsDetails></DomainGetInfoResult>']);
        $domain = $this->moduleDomain('namecheap');

        $info = $this->namecheap->info($domain, self::CREDENTIALS);

        $this->assertSame(DomainInfo::ACTIVE, $info->status);
        $this->assertSame('2027-03-15', $info->expiry->toDateString());
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $info->nameservers);
        $this->assertTrue($info->idProtection);
        $this->assertSame('4242', $domain->registrarValue('whoisguard_id'));
    }

    public function test_a_domain_stays_active_on_its_expiry_day(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 15)->setTime(4, 10));
        $this->fake(['namecheap.domains.getInfo' => '<DomainGetInfoResult Status="Ok"><DomainDetails><ExpiredDate>03/15/2027</ExpiredDate></DomainDetails></DomainGetInfoResult>']);

        $this->assertSame(DomainInfo::ACTIVE, $this->namecheap->info($this->moduleDomain('namecheap'), self::CREDENTIALS)->status);
    }

    public function test_a_domain_not_in_the_account_is_gone(): void
    {
        $this->refuse('2019166', 'Domain not found');

        $this->assertSame(DomainInfo::GONE, $this->namecheap->info($this->moduleDomain('namecheap'), self::CREDENTIALS)->status);
    }

    public function test_id_protection_uses_the_whoisguard_id(): void
    {
        $this->fake([]);
        $domain = $this->moduleDomain('namecheap', attributes: ['registrar_data' => ['whoisguard_id' => '4242']]);

        $this->namecheap->setIdProtection($domain, true, self::CREDENTIALS);

        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'namecheap.whoisguard.enable' && $request['WhoisguardID'] === '4242' && filled($request['ForwardedToEmail']));
    }

    public function test_dns_hosts_are_read_and_replaced(): void
    {
        $this->fake(['namecheap.domains.dns.getHosts' => '<DomainDNSGetHostsResult Domain="example.com"><host Name="@" Type="A" Address="192.0.2.1" TTL="1800" /><host Name="@" Type="MX" Address="mx.example.com" MXPref="10" TTL="1800" /></DomainDNSGetHostsResult>']);
        $domain = $this->moduleDomain('namecheap');

        $records = $this->namecheap->dnsRecords($domain, self::CREDENTIALS);
        $this->assertSame(['A', 'MX'], array_map(fn (DnsRecord $record) => $record->type, $records));

        $this->namecheap->setDnsRecords($domain, $records, self::CREDENTIALS);
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'namecheap.domains.dns.setHosts'
            && $request['SLD'] === 'example' && $request['TLD'] === 'com' && $request['Address1'] === '192.0.2.1' && $request['EmailType'] === 'MX' && (int) $request['MXPref2'] === 10);
    }

    public function test_management_calls(): void
    {
        $this->fake(['namecheap.domains.renew' => '<DomainRenewResult DomainName="example.com" Renew="true"><DomainDetails><ExpiredDate>01/01/2029</ExpiredDate></DomainDetails></DomainRenewResult>']);
        $domain = $this->moduleDomain('namecheap');

        $this->assertSame('2029-01-01', $this->namecheap->renew($domain, 2, self::CREDENTIALS)->expiry->toDateString());
        $this->namecheap->setNameservers($domain, ['ns1.a.test', 'ns2.a.test'], self::CREDENTIALS);
        $this->namecheap->setLock($domain, true, self::CREDENTIALS);

        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'namecheap.domains.dns.setCustom' && $request['Nameservers'] === 'ns1.a.test,ns2.a.test');
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'namecheap.domains.setRegistrarLock' && $request['LockAction'] === 'LOCK');

        $this->expectException(RegistrarError::class);
        $this->namecheap->eppCode($domain, self::CREDENTIALS);
    }
}
