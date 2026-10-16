<?php

namespace Tests\Feature\Domains\Registrars;

use App\Domains\DnsRecord;
use App\Domains\DomainInfo;
use App\Domains\RegistrarError;
use App\Domains\Registrars\Enom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class EnomTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const TEST_URL = 'https://resellertest.enom.com/interface.asp';

    private const CREDENTIALS = ['username' => 'reseller', 'api_token' => 'token-1', 'test_mode' => '1'];

    private const COMMAND = 'command';

    private Enom $enom;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->enom = new Enom;
    }

    /** @param  array<string, string>  $replies  XML bodies (inside the root element) by command */
    private function fake(array $replies): void
    {
        Http::fake(function (Request $request) use ($replies) {
            $body = $replies[$request[self::COMMAND]] ?? '';

            return Http::response("<?xml version=\"1.0\"?><interface-response>{$body}<ErrCount>0</ErrCount><Done>true</Done></interface-response>");
        });
    }

    private function refuse(string $reason): void
    {
        Http::fake(fn () => Http::response("<interface-response><ErrCount>1</ErrCount><errors><Err1>{$reason}</Err1></errors></interface-response>"));
    }

    public function test_availability_reads_each_rrp_code(): void
    {
        $this->fake(['Check' => '<DomainCount>2</DomainCount><Domain1>free.com</Domain1><RRPCode1>210</RRPCode1><Domain2>taken.com</Domain2><RRPCode2>211</RRPCode2>']);

        $this->assertSame(['free.com' => true, 'taken.com' => false], $this->enom->available(['free.com', 'taken.com'], self::CREDENTIALS));

        Http::assertSent(fn (Request $request) => $request->url() === self::TEST_URL
            && $request['uid'] === 'reseller' && $request['pw'] === 'token-1' && $request['responsetype'] === 'xml'
            && $request['DomainList'] === 'free.com,taken.com');
    }

    public function test_registration_sends_the_term_nameservers_and_every_contact(): void
    {
        $this->fake(['Purchase' => '<OrderID>12345</OrderID>']);
        $domain = $this->moduleDomain('enom', 'shop.co.uk', ['years' => 2, 'id_protection' => true]);

        $result = $this->enom->register($domain, self::CREDENTIALS);

        $this->assertTrue($result->ok);
        $this->assertSame(['order_id' => '12345'], $result->data);
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'Purchase'
            && $request['SLD'] === 'shop' && $request['TLD'] === 'co.uk' && (int) $request['NumYears'] === 2
            && $request['NS1'] === 'ns1.host.test' && $request['NS2'] === 'ns2.host.test'
            && $request['RegistrantCity'] === 'Springfield' && $request['AuxBillingPhone'] === '+1.5550100');
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'EnableServices' && $request['Service'] === 'WPPS');
    }

    public function test_errors_are_raised_with_enoms_reason(): void
    {
        $this->refuse('Domain name not available');

        $this->expectException(RegistrarError::class);
        $this->expectExceptionMessage('Enom refused Purchase: Domain name not available');
        $this->enom->register($this->moduleDomain('enom'), self::CREDENTIALS);
    }

    public function test_transfers_send_the_auth_code_and_wait(): void
    {
        $this->fake(['TP_CreateOrder' => '<transferorder><transferorderid>777</transferorderid></transferorder>']);

        $result = $this->enom->transfer($this->moduleDomain('enom', attributes: ['epp_code' => 'AUTH']), self::CREDENTIALS);

        $this->assertTrue($result->inProgress);
        $this->assertSame('777', $result->data['transfer_order_id']);
        Http::assertSent(fn (Request $request) => $request['SLD1'] === 'example' && $request['TLD1'] === 'com' && $request['AuthInfo1'] === 'AUTH');
    }

    public function test_info_reads_expiry_nameservers_and_lock(): void
    {
        $this->fake([
            'GetDomainInfo' => '<GetDomainInfo><status><expiration>3/15/2027 4:00:00 AM</expiration><registrationstatus>Registered</registrationstatus></status>'
                .'<services><entry name="dnsserver"><configuration><dns>NS1.HOST.TEST</dns><dns>ns2.host.test</dns></configuration></entry></services></GetDomainInfo>',
            'GetRegLock' => '<reg-lock>1</reg-lock>',
        ]);

        $info = $this->enom->info($this->moduleDomain('enom'), self::CREDENTIALS);

        $this->assertSame(DomainInfo::ACTIVE, $info->status);
        $this->assertSame('2027-03-15', $info->expiry->toDateString());
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $info->nameservers);
        $this->assertTrue($info->locked);
    }

    public function test_a_domain_no_longer_in_the_account_is_gone(): void
    {
        $this->refuse('Domain name ID not found');

        $this->assertSame(DomainInfo::GONE, $this->enom->info($this->moduleDomain('enom'), self::CREDENTIALS)->status);
    }

    public function test_dns_hosts_are_read_and_replaced(): void
    {
        $this->fake(['GetHosts' => '<host><name>@</name><type>A</type><address>192.0.2.1</address></host>'
            .'<host><name>@</name><type>MX</type><address>mail.example.com.</address><mxpref>5</mxpref></host>'
            .'<host><name>x</name><type>URL</type><address>http://a</address></host>']);
        $domain = $this->moduleDomain('enom');

        $records = $this->enom->dnsRecords($domain, self::CREDENTIALS);
        $this->assertCount(2, $records);
        $this->assertSame(5, $records[1]->priority);

        $this->enom->setDnsRecords($domain, [new DnsRecord('www', 'CNAME', 'example.com'), new DnsRecord('@', 'MX', 'mx.example.com', 20)], self::CREDENTIALS);
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'SetHosts'
            && $request['HostName1'] === 'www' && $request['RecordType1'] === 'CNAME' && $request['Address2'] === 'mx.example.com' && (int) $request['MXPref2'] === 20);
    }

    public function test_management_calls(): void
    {
        $this->fake([]);
        $domain = $this->moduleDomain('enom');

        $this->enom->setNameservers($domain, ['ns1.a.test', 'ns2.a.test'], self::CREDENTIALS);
        $this->enom->setLock($domain, false, self::CREDENTIALS);
        $this->assertNull($this->enom->eppCode($domain, self::CREDENTIALS));
        $this->enom->renew($domain, 3, self::CREDENTIALS);
        $this->enom->testConnection(self::CREDENTIALS);

        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'ModifyNS' && $request['NS2'] === 'ns2.a.test');
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'SetRegLock' && (int) $request['UnlockRegistrar'] === 1);
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'SynchAuthInfo' && $request['EmailEPP'] === 'True');
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'Extend' && (int) $request['NumYears'] === 3);
        Http::assertSent(fn (Request $request) => $request[self::COMMAND] === 'GetBalance');
    }
}
