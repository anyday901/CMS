<?php

namespace Tests\Feature\Servers;

use App\Billing\ServiceLifecycle;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Modules\Cpanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CpanelModuleTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://cp1.example.test:2087/json-api/';

    private const DOMAIN = 'example.com';

    private Server $server;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->server = Server::factory()->create(['name' => 'cp1', 'hostname' => 'cp1.example.test', 'username' => 'reseller', 'api_token' => 'TOKEN1']);
        $product = Product::factory()->create(['name' => 'Starter', 'module' => 'cpanel', 'module_config' => ['package' => 'reseller_basic']]);
        $client = Client::factory()->create(['email' => 'ada@example.test']);
        $this->service = Service::factory()->for($product)->for($client)->pending()->create(['label' => self::DOMAIN]);
    }

    public function test_creating_a_service_puts_it_on_a_server_and_makes_the_account(): void
    {
        $this->fakeWhm();

        app(ServiceLifecycle::class)->activate($this->service);

        $service = $this->service->fresh();
        $this->assertSame('done', $service->provisioning_status, (string) $service->provisioning_error);
        $this->assertSame($this->server->id, $service->server_id);
        $this->assertSame('example', $service->username);
        $this->assertSame(16, strlen($service->password));
        Http::assertSent(fn (Request $request) => $request->url() === self::WHM.'createacct?api.version=1'
            && $request->method() === 'POST'
            && $request->header('Authorization')[0] === 'whm reseller:TOKEN1'
            && $request['username'] === 'example'
            && $request['domain'] === self::DOMAIN
            && $request['plan'] === 'reseller_basic'
            && $request['password'] === $service->password
            && $request['contactemail'] === 'ada@example.test');

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/services/{$service->id}")->assertOk()->assertSee('cp1')->assertSee('Save changes');
    }

    public function test_an_account_an_earlier_run_made_is_kept(): void
    {
        $this->service->forceFill(['server_id' => $this->server->id, 'username' => 'example', 'password' => 'Secret-pass1'])->save();
        $this->fakeWhm(['acct' => [['user' => 'example', 'domain' => self::DOMAIN]]]);

        $result = $this->module()->create($this->service);

        $this->assertTrue($result->ok);
        $this->assertSame('The account example was already on cp1.', $result->message);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'createacct'));
    }

    public function test_a_username_taken_by_another_domain_fails_create(): void
    {
        $this->service->forceFill(['server_id' => $this->server->id, 'username' => 'example'])->save();
        $this->fakeWhm(['acct' => [['user' => 'example', 'domain' => 'other.test']]]);

        $result = $this->module()->create($this->service);

        $this->assertFalse($result->ok);
        $this->assertSame('The username example is already used on cp1 by other.test. Enter another username on the service page, then run create again.', $result->message);
    }

    public function test_usernames_come_from_the_domain_and_are_unique_on_the_server(): void
    {
        $this->fakeWhm();
        Service::factory()->create(['server_id' => $this->server->id, 'username' => 'testsite']);
        $this->service->forceFill(['label' => 'test-site.example', 'server_id' => $this->server->id])->save();

        $this->module()->create($this->service);
        $this->assertSame('utestsit', $this->service->fresh()->username, 'cPanel refuses usernames starting with "test".');

        $other = Service::factory()->for($this->service->product)->create(['label' => 'utestsite.example', 'server_id' => $this->server->id]);
        $this->module()->create($other);
        $this->assertMatchesRegularExpression('/^utest\d{3}$/', $other->fresh()->username);
    }

    public function test_actions_on_an_account_reach_whm(): void
    {
        $this->fakeWhm(['acct' => [['user' => 'example', 'domain' => self::DOMAIN]]]);
        $this->service->forceFill(['server_id' => $this->server->id, 'username' => 'example', 'password' => 'New-pass-123', 'suspension_reason' => 'overdue'])->save();
        $module = $this->module();

        $this->assertTrue($module->suspend($this->service)->ok);
        $this->assertTrue($module->unsuspend($this->service)->ok);
        $this->assertTrue($module->changePackage($this->service)->ok);
        $this->assertTrue($module->changePassword($this->service)->ok);
        $this->assertSame('Removed the account example from cp1.', $module->terminate($this->service)->message);

        $sent = Http::recorded()->map(fn (array $pair) => [str($pair[0]->url())->after('json-api/')->before('?')->value(), $pair[0]->data()])->all();
        $this->assertSame(['suspendacct', ['user' => 'example', 'reason' => 'Suspended by '.config('app.name').' (overdue)']], $sent[0]);
        $this->assertSame(['unsuspendacct', ['user' => 'example']], $sent[1]);
        $this->assertSame(['changepackage', ['user' => 'example', 'pkg' => 'reseller_basic']], $sent[2]);
        $this->assertSame(['passwd', ['user' => 'example', 'password' => 'New-pass-123']], $sent[3]);
        $this->assertSame(['removeacct', ['username' => 'example']], $sent[5]);
    }

    public function test_terminating_an_account_that_is_gone_succeeds(): void
    {
        $this->fakeWhm();
        $this->service->forceFill(['server_id' => $this->server->id, 'username' => 'example'])->save();

        $this->assertSame('The account example was already gone from cp1.', $this->module()->terminate($this->service)->message);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'removeacct'));
    }

    public function test_whm_errors_become_the_failure_message(): void
    {
        $this->service->forceFill(['server_id' => $this->server->id])->save();
        Http::fake([
            self::WHM.'listaccts*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]]),
            self::WHM.'createacct*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'The package reseller_basic does not exist.']]),
        ]);

        $result = $this->module()->create($this->service);

        $this->assertFalse($result->ok);
        $this->assertSame('WHM: The package reseller_basic does not exist.', $result->message);
    }

    public function test_an_unreachable_server_fails_the_action(): void
    {
        $this->service->forceFill(['server_id' => $this->server->id, 'username' => 'example'])->save();
        Http::fake(['*' => Http::failedConnection('Connection timed out')]);

        $this->assertSame("Couldn't reach cp1.example.test: Connection timed out", $this->module()->suspend($this->service)->message);
    }

    public function test_the_password_is_used_without_an_api_token(): void
    {
        $this->server->update(['api_token' => null, 'password' => 'rootpass', 'secure' => false]);
        $this->service->forceFill(['server_id' => $this->server->id, 'username' => 'example'])->save();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => []])]);

        $this->module()->unsuspend($this->service);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://cp1.example.test:2086/json-api/unsuspendacct')
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('reseller:rootpass'));
    }

    public function test_create_needs_a_domain_and_a_package(): void
    {
        Http::fake();
        $this->service->forceFill(['server_id' => $this->server->id, 'label' => null])->save();
        $this->assertSame('The service needs a domain name, such as example.com. Set it on the service page, then run create again.', $this->module()->create($this->service)->message);

        $this->service->forceFill(['label' => self::DOMAIN])->save();
        $this->service->product->update(['module_config' => []]);
        $this->assertSame('Set the WHM package on the product Starter first.', $this->module()->create($this->service->fresh())->message);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'createacct'));
    }

    private function module(): Cpanel
    {
        return app(ModuleRegistry::class)->find('cpanel');
    }

    /** @param  array<string, mixed>  $accounts  listaccts data */
    private function fakeWhm(array $accounts = ['acct' => []]): void
    {
        Http::fake([
            self::WHM.'listaccts*' => Http::response(['metadata' => ['result' => 1], 'data' => $accounts]),
            self::WHM.'*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => []]),
        ]);
    }
}
