<?php

namespace Tests\Feature\Domains\Registrars;

use App\Domains\DnsRecord;
use App\Domains\DomainInfo;
use App\Domains\RegistrarError;
use App\Domains\Registrars\OpenSrs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class OpenSrsTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const TEST_URL = 'https://horizon.opensrs.net:55443';

    private const CREDENTIALS = ['username' => 'reseller', 'api_key' => 'key-1', 'test_mode' => '1'];

    private OpenSrs $srs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->srs = new OpenSrs;
    }

    /**
     * Answers each request with the reply for its action (and GET type), or success.
     *
     * @param  array<string, array<string, mixed>|callable>  $replies
     */
    private function fake(array $replies): void
    {
        Http::fake(function (Request $request) use ($replies) {
            $sent = OpenSrs::decode($request->body());
            $key = $sent['action'] === 'GET' ? 'GET:'.$sent['attributes']['type'] : $sent['action'];
            $reply = $replies[$key] ?? [];
            $reply = is_callable($reply) ? $reply($sent) : $reply;

            return Http::response(OpenSrs::encode($reply + ['protocol' => 'XCP', 'action' => 'REPLY', 'is_success' => '1', 'response_code' => '200', 'response_text' => 'Command successful']));
        });
    }

    /** @return array<string, mixed> the data block of the first request for $action */
    private function sent(string $action): array
    {
        $found = Http::recorded(fn (Request $request) => OpenSrs::decode($request->body())['action'] === $action)->first();
        $this->assertNotNull($found, "No {$action} request was sent.");

        return OpenSrs::decode($found[0]->body());
    }

    public function test_requests_are_signed_ops_envelopes(): void
    {
        $this->fake(['LOOKUP' => fn ($sent) => $sent['attributes']['domain'] === 'free.com'
            ? ['is_success' => '0', 'response_code' => '210', 'response_text' => 'Domain available']
            : ['is_success' => '0', 'response_code' => '211', 'response_text' => 'Domain taken']]);

        $this->assertSame(['free.com' => true, 'taken.com' => false], $this->srs->available(['free.com', 'taken.com'], self::CREDENTIALS));

        Http::assertSent(fn (Request $request) => $request->url() === self::TEST_URL
            && $request->header('X-Username')[0] === 'reseller'
            && $request->header('X-Signature')[0] === md5(md5($request->body().'key-1').'key-1')
            && str_contains($request->body(), '<!DOCTYPE OPS_envelope'));
    }

    public function test_the_envelope_round_trips_nested_data(): void
    {
        $data = ['action' => 'SW_REGISTER', 'attributes' => ['nameserver_list' => [['sortorder' => '1', 'name' => 'ns1.a.test']], 'contact_set' => ['owner' => ['first_name' => 'Ada & Co']]]];

        $this->assertSame($data, OpenSrs::decode(OpenSrs::encode($data)));
    }

    public function test_replies_with_external_entities_are_not_expanded(): void
    {
        $body = '<?xml version="1.0"?><!DOCTYPE OPS_envelope [<!ENTITY x SYSTEM "file:///etc/passwd">]><OPS_envelope><body><data_block><dt_assoc><item key="response_text">&x;</item></dt_assoc></data_block></body></OPS_envelope>';

        $this->assertStringNotContainsString('root:', (string) (OpenSrs::decode($body)['response_text'] ?? ''));
    }

    public function test_registration_sends_contacts_nameservers_and_a_kept_profile(): void
    {
        $this->fake(['SW_REGISTER' => ['attributes' => ['id' => '3735281']]]);
        $domain = $this->moduleDomain('opensrs', attributes: ['years' => 2, 'id_protection' => true]);

        $result = $this->srs->register($domain, self::CREDENTIALS);

        $this->assertSame('3735281', $result->data['order_id']);
        $attributes = $this->sent('SW_REGISTER')['attributes'];
        $this->assertSame(['new', '2', '1'], [$attributes['reg_type'], $attributes['period'], $attributes['f_whois_privacy']]);
        $this->assertSame('ns2.host.test', $attributes['nameserver_list'][1]['name']);
        $this->assertSame('+1.5550100', $attributes['contact_set']['tech']['phone']);
        $this->assertSame($result->data['reg_username'], $attributes['reg_username']);
        $this->assertSame($attributes['reg_password'], $domain->refresh()->registrarValue('reg_password'));
    }

    public function test_refusals_carry_the_response_text(): void
    {
        $this->fake(['SW_REGISTER' => ['is_success' => '0', 'response_code' => '485', 'response_text' => 'Domain taken']]);

        $this->expectException(RegistrarError::class);
        $this->expectExceptionMessage('OpenSRS refused SW_REGISTER: Domain taken');
        $this->srs->register($this->moduleDomain('opensrs'), self::CREDENTIALS);
    }

    public function test_transfers_and_renewals(): void
    {
        $this->fake(['SW_REGISTER' => ['attributes' => ['id' => '1']], 'RENEW' => ['attributes' => ['registration expiration date' => '2029-01-01 00:00:00']]]);
        $domain = $this->moduleDomain('opensrs', attributes: ['epp_code' => 'AUTH', 'expiry_date' => '2027-01-01']);

        $this->assertTrue($this->srs->transfer($domain, self::CREDENTIALS)->inProgress);
        $this->assertSame(['transfer', 'AUTH'], [$this->sent('SW_REGISTER')['attributes']['reg_type'], $this->sent('SW_REGISTER')['attributes']['auth_info']]);

        $this->assertSame('2029-01-01', $this->srs->renew($domain, 2, self::CREDENTIALS)->expiry->toDateString());
        $this->assertSame('2027', $this->sent('RENEW')['attributes']['currentexpirationyear']);
    }

    public function test_info_combines_details_lock_and_privacy(): void
    {
        $this->fake([
            'GET:all_info' => ['attributes' => ['expiredate' => '2027-03-15 00:00:00', 'nameserver_list' => [['name' => 'NS1.HOST.TEST', 'sortorder' => '1'], ['name' => 'ns2.host.test', 'sortorder' => '2']]]],
            'GET:status' => ['attributes' => ['lock_state' => '1']],
            'GET:whois_privacy_state' => ['attributes' => ['state' => 'enabled']],
            'GET:domain_auth_info' => ['attributes' => ['domain_auth_info' => 'SECRET']],
        ]);
        $domain = $this->moduleDomain('opensrs');

        $info = $this->srs->info($domain, self::CREDENTIALS);

        $this->assertSame(DomainInfo::ACTIVE, $info->status);
        $this->assertSame('2027-03-15', $info->expiry->toDateString());
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $info->nameservers);
        $this->assertTrue($info->locked);
        $this->assertTrue($info->idProtection);
        $this->assertSame('SECRET', $this->srs->eppCode($domain, self::CREDENTIALS));
    }

    public function test_a_domain_not_in_the_account_is_gone(): void
    {
        $this->fake(['GET:all_info' => ['is_success' => '0', 'response_code' => '415', 'response_text' => 'Authentication Error.']]);

        $this->assertSame(DomainInfo::GONE, $this->srs->info($this->moduleDomain('opensrs'), self::CREDENTIALS)->status);
    }

    public function test_dns_zones_are_read_and_created_when_missing(): void
    {
        $this->fake([
            'GET_DNS_ZONE' => ['attributes' => ['records' => ['A' => [['subdomain' => '', 'ip_address' => '192.0.2.1']], 'MX' => [['subdomain' => '', 'hostname' => 'mx.example.com', 'priority' => '5']]]]],
            'SET_DNS_ZONE' => ['is_success' => '0', 'response_code' => '400', 'response_text' => 'DNS zone does not exist'],
        ]);
        $domain = $this->moduleDomain('opensrs');

        $records = $this->srs->dnsRecords($domain, self::CREDENTIALS);
        $this->assertSame(['@', 'A', '192.0.2.1'], [$records[0]->host, $records[0]->type, $records[0]->value]);
        $this->assertSame(5, $records[1]->priority);

        $this->srs->setDnsRecords($domain, [new DnsRecord('www', 'CNAME', 'example.com')], self::CREDENTIALS);
        $this->assertSame(['subdomain' => 'www', 'hostname' => 'example.com'], $this->sent('CREATE_DNS_ZONE')['attributes']['records']['CNAME'][0]);
    }

    public function test_management_calls(): void
    {
        $this->fake([]);
        $domain = $this->moduleDomain('opensrs');

        $this->srs->setNameservers($domain, ['ns1.a.test', 'ns2.a.test'], self::CREDENTIALS);
        $this->assertSame(['ns1.a.test', 'ns2.a.test'], $this->sent('ADVANCED_UPDATE_NAMESERVERS')['attributes']['assign_ns']);

        $this->srs->setLock($domain, true, self::CREDENTIALS);
        $this->assertSame(['status', '1'], [$this->sent('MODIFY')['attributes']['data'], $this->sent('MODIFY')['attributes']['lock_state']]);

        $this->srs->testConnection(self::CREDENTIALS);
        $this->assertSame('BALANCE', $this->sent('GET_BALANCE')['object']);
    }
}
