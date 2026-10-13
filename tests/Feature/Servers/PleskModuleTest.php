<?php

namespace Tests\Feature\Servers;

use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Modules\Plesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PleskModuleTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = 'https://plesk1.example.test:8443/enterprise/control/agent.php';

    private const NOT_FOUND = '<result><status>error</status><errcode>1013</errcode><errtext>Object not found.</errtext></result>';

    private const OK = '<result><status>ok</status><id>1</id></result>';

    private Server $server;

    private Service $service;

    /** @var array<string, string> replies by "type/operation" */
    private array $replies = [];

    /** A whole packet to answer with instead. */
    private ?string $packet = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = Server::factory()->create(['name' => 'plesk1', 'module' => 'plesk', 'hostname' => 'plesk1.example.test', 'username' => 'admin', 'api_token' => 'SECRETKEY', 'ip_address' => '203.0.113.5']);
        $product = Product::factory()->create(['name' => 'Web', 'module' => 'plesk', 'module_config' => ['plan' => 'Unlimited']]);
        $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
        $this->service = Service::factory()->for($product)->for($client)->pending()->create(['label' => 'example.com']);
        $this->service->forceFill(['server_id' => $this->server->id])->save();

        // Answers each operation in the packet with its reply, ok by default.
        Http::fake(function (Request $request) {
            $answer = '';

            foreach (simplexml_load_string($request->body())->children() as $operations) {
                foreach ($operations->children() as $node) {
                    [$type, $operation] = [$operations->getName(), $node->getName()];
                    $result = $this->replies["{$type}/{$operation}"] ?? self::OK;
                    $answer .= "<{$type}><{$operation}>{$result}</{$operation}></{$type}>";
                }
            }

            return Http::response($this->packet ?? "<?xml version=\"1.0\" encoding=\"UTF-8\"?><packet version=\"1.6.9.1\">{$answer}</packet>");
        });
    }

    public function test_create_makes_a_customer_and_a_subscription_on_the_plan(): void
    {
        $this->replies = [
            'customer/get' => self::NOT_FOUND,
            'customer/add' => '<result><status>ok</status><id>7</id><guid>g</guid></result>',
            'webspace/get' => self::NOT_FOUND,
            'webspace/add' => '<result><status>ok</status><id>12</id><guid>g</guid></result>',
        ];

        $result = $this->module()->create($this->service);

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertSame(['plesk_customer_id' => 7, 'plesk_subscription_id' => 12], $result->data);
        $this->assertSame('example', $this->service->username);
        Http::assertSent(fn (Request $request) => $request->url() === self::AGENT
            && $request->header('KEY')[0] === 'SECRETKEY'
            && $request->header('Content-Type')[0] === 'text/xml'
            && str_contains($request->body(), '<customer><add><gen_info><pname>Ada Lovelace</pname><login>example</login><passwd>'.$this->service->password.'</passwd><email>ada@example.test</email></gen_info>'));
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<webspace><add><gen_setup><name>example.com</name><owner-id>7</owner-id><htype>vrt_hst</htype><ip_address>203.0.113.5</ip_address><status>0</status></gen_setup>')
            && str_contains($request->body(), '<property><name>ftp_login</name><value>example</value></property>')
            && str_contains($request->body(), '<plan-name>Unlimited</plan-name></add>'));
    }

    public function test_a_subscription_an_earlier_run_made_is_kept_unless_another_customer_owns_it(): void
    {
        $this->service->forceFill(['username' => 'example', 'password' => 'Secret-pass1'])->save();
        $this->replies = [
            'customer/get' => '<result><status>ok</status><id>7</id></result>',
            'webspace/get' => '<result><status>ok</status><id>12</id><data><gen_info><owner-login>example</owner-login></gen_info></data></result>',
        ];

        $result = $this->module()->create($this->service);
        $this->assertSame('The subscription was already on plesk1 for the customer example.', $result->message);
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), '<add>'));

        $this->replies['webspace/get'] = '<result><status>ok</status><id>12</id><data><gen_info><owner-login>someone</owner-login></gen_info></data></result>';
        $this->assertSame('example.com is already a subscription of another customer on plesk1.', $this->module()->create($this->service)->message);
    }

    public function test_suspend_and_unsuspend_set_the_subscription_status(): void
    {
        $this->service->forceFill(['username' => 'example', 'provisioning_data' => ['plesk_subscription_id' => 12]])->save();

        $this->assertTrue($this->module()->suspend($this->service)->ok);
        $this->assertTrue($this->module()->unsuspend($this->service)->ok);

        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<webspace><set><filter><id>12</id></filter><values><gen_setup><status>16</status></gen_setup></values></set>'));
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<gen_setup><status>0</status></gen_setup>'));
    }

    public function test_terminate_deletes_the_customer_and_is_fine_when_it_is_gone(): void
    {
        $this->service->forceFill(['username' => 'example'])->save();

        $this->assertSame('Removed the customer example and their subscription from plesk1.', $this->module()->terminate($this->service)->message);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<customer><del><filter><login>example</login></filter></del></customer>'));

        $this->replies['customer/del'] = self::NOT_FOUND;
        $this->assertSame('The customer example was already gone from plesk1.', $this->module()->terminate($this->service)->message);
    }

    public function test_change_package_switches_to_the_plan_by_its_guid(): void
    {
        $this->service->forceFill(['username' => 'example'])->save();
        $this->replies['service-plan/get'] = '<result><status>ok</status><id>3</id><guid>5c1b-guid</guid></result>';

        $this->assertTrue($this->module()->changePackage($this->service)->ok);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<webspace><switch-subscription><filter><name>example.com</name></filter><plan-guid>5c1b-guid</plan-guid></switch-subscription>'));

        $this->replies['service-plan/get'] = self::NOT_FOUND;
        $this->assertSame('Plesk has no service plan called Unlimited.', $this->module()->changePackage($this->service)->message);
    }

    public function test_change_password_sets_the_customer_and_ftp_passwords(): void
    {
        $this->service->forceFill(['username' => 'example', 'password' => 'New-pass-123'])->save();

        $this->assertTrue($this->module()->changePassword($this->service)->ok);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<customer><set><filter><login>example</login></filter><values><gen_info><passwd>New-pass-123</passwd></gen_info></values></set></customer>')
            && str_contains($request->body(), '<property><name>ftp_password</name><value>New-pass-123</value></property>'));
    }

    public function test_errors_from_plesk_fail_the_action(): void
    {
        $this->service->forceFill(['username' => 'example'])->save();
        $this->replies['webspace/set'] = '<result><status>error</status><errcode>1023</errcode><errtext>Operation failed.</errtext></result>';
        $this->assertSame('Plesk: Operation failed.', $this->module()->suspend($this->service)->message);

        $this->packet = '<?xml version="1.0"?><packet><system><status>error</status><errcode>1001</errcode><errtext>Authentication failed.</errtext></system></packet>';
        $this->server->update(['api_token' => null, 'password' => 'adminpass']);
        $this->assertSame('Plesk: Authentication failed.', $this->module()->unsuspend($this->service->fresh())->message);
        Http::assertSent(fn (Request $request) => $request->hasHeader('HTTP_AUTH_LOGIN', 'admin') && $request->hasHeader('HTTP_AUTH_PASSWD', 'adminpass'));
    }

    public function test_test_connection_reports_the_version(): void
    {
        $this->replies['server/get'] = '<result><status>ok</status><stat><version><plesk_version>18.0.62</plesk_version></version></stat></result>';

        $this->assertSame('Connected to Plesk 18.0.62.', $this->module()->testConnection($this->server)->message);
    }

    private function module(): Plesk
    {
        return app(ModuleRegistry::class)->find('plesk');
    }
}
