<?php

namespace Tests\Feature\Servers;

use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Modules\DirectAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectAdminModuleTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://da1.example.test:2222/';

    private const NO_USERS = 'list[]=someone';

    private const SELECT_USERS = 'CMD_API_SELECT_USERS';

    private const SHOW_USERS = 'CMD_API_SHOW_USERS*';

    private const HAS_EXAMPLE = 'list[]=example';

    private Server $server;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = Server::factory()->create(['name' => 'da1', 'module' => 'directadmin', 'hostname' => 'da1.example.test', 'username' => 'reseller', 'password' => 'resellerpass', 'api_token' => null, 'ip_address' => '203.0.113.7']);
        $product = Product::factory()->create(['name' => 'Web', 'module' => 'directadmin', 'module_config' => ['package' => 'bronze']]);
        $client = Client::factory()->create(['email' => 'ada@example.test']);
        $this->service = Service::factory()->for($product)->for($client)->pending()->create(['label' => 'example.com']);
        $this->service->forceFill(['server_id' => $this->server->id])->save();
    }

    public function test_create_adds_the_user_on_the_package_and_ip(): void
    {
        $this->fakeApi([self::SHOW_USERS => self::NO_USERS, 'CMD_API_ACCOUNT_USER' => 'error=0&text=User created']);

        $result = $this->module()->create($this->service);

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertSame('Created the user example on da1.', $result->message);
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'CMD_API_ACCOUNT_USER'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('reseller:resellerpass')
            && $request->data() === [
                'action' => 'create', 'add' => 'Submit', 'username' => 'example', 'email' => 'ada@example.test',
                'passwd' => $this->service->password, 'passwd2' => $this->service->password, 'domain' => 'example.com',
                'package' => 'bronze', 'ip' => '203.0.113.7', 'notify' => 'no',
            ]);
    }

    public function test_a_user_an_earlier_run_made_is_kept_unless_it_has_another_domain(): void
    {
        $this->service->forceFill(['username' => 'example', 'password' => 'Secret-pass1'])->save();
        $this->fakeApi([self::SHOW_USERS => self::HAS_EXAMPLE, 'CMD_API_SHOW_USER_CONFIG*' => 'domain=example.com&package=bronze']);

        $this->assertSame('The user example was already on da1.', $this->module()->create($this->service)->message);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'CMD_API_ACCOUNT_USER'));
    }

    public function test_a_username_used_by_another_domain_fails_create(): void
    {
        $this->service->forceFill(['username' => 'example', 'password' => 'Secret-pass1'])->save();
        $this->fakeApi([self::SHOW_USERS => self::HAS_EXAMPLE, 'CMD_API_SHOW_USER_CONFIG*' => 'domain=other.test']);

        $this->assertSame('The username example is already used on da1 by other.test. Enter another username on the service page, then run create again.', $this->module()->create($this->service)->message);
    }

    public function test_actions_on_a_user_reach_directadmin(): void
    {
        $this->service->forceFill(['username' => 'example', 'password' => 'New-pass-123'])->save();
        // DirectAdmin answers some commands with a redirect when they work.
        $this->fakeApi([self::SHOW_USERS => self::HAS_EXAMPLE, self::SELECT_USERS => Http::response('', 302, ['Location' => '/CMD_SELECT_USERS'])]);
        $module = $this->module();

        $this->assertTrue($module->suspend($this->service)->ok);
        $this->assertTrue($module->unsuspend($this->service)->ok);
        $this->assertTrue($module->changePackage($this->service)->ok);
        $this->assertTrue($module->changePassword($this->service)->ok);
        $this->assertSame('Removed the user example from da1.', $module->terminate($this->service)->message);

        $sent = Http::recorded()->map(fn (array $pair) => [str($pair[0]->url())->after(self::API)->before('?')->value(), $pair[0]->data()])->all();
        $this->assertSame([self::SELECT_USERS, ['location' => 'CMD_SELECT_USERS', 'suspend' => 'Suspend', 'dosuspend' => 'yes', 'select0' => 'example']], $sent[0]);
        $this->assertSame([self::SELECT_USERS, ['location' => 'CMD_SELECT_USERS', 'suspend' => 'Unsuspend', 'dounsuspend' => 'yes', 'select0' => 'example']], $sent[1]);
        $this->assertSame(['CMD_API_MODIFY_USER', ['action' => 'package', 'user' => 'example', 'package' => 'bronze']], $sent[2]);
        $this->assertSame(['CMD_API_USER_PASSWD', ['username' => 'example', 'passwd' => 'New-pass-123', 'passwd2' => 'New-pass-123']], $sent[3]);
        $this->assertSame([self::SELECT_USERS, ['confirmed' => 'Confirm', 'delete' => 'yes', 'select0' => 'example']], $sent[5]);
    }

    public function test_terminating_a_user_that_is_gone_succeeds(): void
    {
        $this->service->forceFill(['username' => 'example'])->save();
        $this->fakeApi([self::SHOW_USERS => self::NO_USERS]);

        $this->assertSame('The user example was already gone from da1.', $this->module()->terminate($this->service)->message);
    }

    public function test_directadmin_errors_become_the_failure_message(): void
    {
        $this->fakeApi([self::SHOW_USERS => self::NO_USERS, 'CMD_API_ACCOUNT_USER' => 'error=1&text=Unable to create the user&details=That domain is already used by another user<br>']);
        $this->assertSame('DirectAdmin: Unable to create the user: That domain is already used by another user', $this->module()->create($this->service)->message);
    }

    public function test_a_login_page_instead_of_an_answer_points_at_the_login(): void
    {
        $this->fakeApi(['*' => '<html><body>Please log in</body></html>']);

        $this->assertSame(
            "DirectAdmin answered CMD_API_LOGIN_TEST with a web page instead of an API answer. Check the server's username and password.",
            $this->module()->testConnection($this->server)->message,
        );
    }

    public function test_test_connection_logs_in(): void
    {
        $this->fakeApi(['CMD_API_LOGIN_TEST' => 'error=0&text=Login OK']);

        $this->assertSame('Connected to DirectAdmin as reseller.', $this->module()->testConnection($this->server)->message);
    }

    private function module(): DirectAdmin
    {
        return app(ModuleRegistry::class)->find('directadmin');
    }

    /** @param  array<string, mixed>  $replies  by command pattern; other commands answer error=0 */
    private function fakeApi(array $replies): void
    {
        $fakes = [];

        foreach ($replies as $command => $reply) {
            $fakes[self::API.$command] = is_string($reply) ? Http::response($reply) : $reply;
        }

        Http::fake($fakes + [self::API.'*' => Http::response('error=0')]);
    }
}
