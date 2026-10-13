<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Clients log in to their control panel and change its password from the portal. */
class ClientAccountTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://cp1.example.test:2087/json-api/';

    private const PASSWORD = 'First-pass1';

    private const NEW_PASSWORD = 'Brand-new-pass9';

    private const SESSION_URL = 'https://cp1.example.test:2083/cpsess123/login/?session=example:abc';

    private Server $server;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->server = Server::factory()->create(['name' => 'cp1', 'hostname' => 'cp1.example.test', 'username' => 'root', 'api_token' => 'TOKEN1']);
        $this->service = $this->serviceOn($this->server, Product::factory()->create(['module' => 'cpanel', 'module_config' => ['package' => 'basic']]));
    }

    public function test_clients_log_in_to_cpanel_without_typing_the_password(): void
    {
        Http::fake([self::WHM.'*' => Http::response(['metadata' => ['result' => 1], 'data' => ['url' => self::SESSION_URL]])]);
        $this->actingAs($this->service->client, 'client');

        $this->get($this->page())->assertOk()->assertSee('Log in to the control panel');
        $this->post($this->page().'/control-panel')->assertRedirect(self::SESSION_URL);

        Http::assertSent(fn (Request $request) => $request->url() === self::WHM.'create_user_session?api.version=1' && $request['user'] === 'example' && $request['service'] === 'cpaneld');
        $this->assertSame(1, Activity::where('description', 'like', 'Logged in to the control panel%')->count());
    }

    public function test_a_panel_that_refuses_shows_the_client_another_way_in(): void
    {
        Http::fake([self::WHM.'*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'User does not exist.']])]);
        $this->actingAs($this->service->client, 'client');

        $this->from($this->page())->post($this->page().'/control-panel')->assertRedirect($this->page())
            ->assertSessionHasErrors(['account' => 'The control panel couldn\'t log you in just now. Use the address and password below, or try again in a few minutes.']);
    }

    public function test_a_login_address_on_another_host_is_not_followed(): void
    {
        Http::fake([self::WHM.'*' => Http::response(['metadata' => ['result' => 1], 'data' => ['url' => 'https://evil.example.test/login']])]);
        $this->actingAs($this->service->client, 'client');

        $this->from($this->page())->post($this->page().'/control-panel')->assertRedirect($this->page())->assertSessionHasErrors('account');
        $this->assertSame(0, Activity::where('description', 'like', 'Logged in to the control panel%')->count());
    }

    public function test_staff_can_open_the_panel_as_the_client(): void
    {
        Http::fake([self::WHM.'*' => Http::response(['metadata' => ['result' => 1], 'data' => ['url' => self::SESSION_URL]])]);
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/services/{$this->service->id}")->assertSee('Log in to the control panel');
        $this->post("/admin/services/{$this->service->id}/control-panel")->assertRedirect(self::SESSION_URL);
    }

    public function test_plesk_logs_the_customer_in_through_a_session_for_their_address(): void
    {
        $server = Server::factory()->create(['module' => 'plesk', 'hostname' => 'plesk1.example.test', 'username' => 'admin', 'api_token' => 'KEY']);
        $service = $this->serviceOn($server, Product::factory()->create(['module' => 'plesk', 'module_config' => ['plan' => 'Unlimited']]));
        Http::fake(['*' => Http::response('<?xml version="1.0"?><packet><server><create_session><result><status>ok</status><id>sess42</id></result></create_session></server></packet>')]);
        $this->actingAs($service->client, 'client');

        $this->post("/portal/services/{$service->id}/control-panel", [], ['REMOTE_ADDR' => '198.51.100.7'])
            ->assertRedirect('https://plesk1.example.test:8443/enterprise/rsession_init.php?PLESKSESSID=sess42');

        Http::assertSent(function (Request $request) {
            $session = simplexml_load_string($request->body())->server->create_session;

            return (string) $session->login === 'example' && (string) $session->data->user_ip === base64_encode('198.51.100.7');
        });
    }

    public function test_directadmin_logs_the_user_in_with_a_one_time_url(): void
    {
        $server = Server::factory()->create(['module' => 'directadmin', 'hostname' => 'da1.example.test', 'username' => 'admin', 'password' => 'da-pass']);
        $service = $this->serviceOn($server, Product::factory()->create(['module' => 'directadmin', 'module_config' => ['package' => 'pro']]));
        Http::fake(['*' => Http::response('error=0&details='.urlencode('https://da1.example.test:2222/api/login/url?key=XYZ'))]);
        $this->actingAs($service->client, 'client');

        $this->post("/portal/services/{$service->id}/control-panel")->assertRedirect('https://da1.example.test:2222/api/login/url?key=XYZ');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'CMD_API_LOGIN_KEYS')
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('admin|example:da-pass')
            && $request['type'] === 'one_time_url');
    }

    public function test_clients_change_the_control_panel_password(): void
    {
        Http::fake([self::WHM.'*' => Http::response(['metadata' => ['result' => 1], 'data' => []])]);
        $this->actingAs($this->service->client, 'client');
        $this->get($this->page())->assertSee('Change the password');

        $this->put($this->page().'/password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHas('status', 'Your new password is being set. Your old one keeps working until it is.');

        Http::assertSent(fn (Request $request) => $request->url() === self::WHM.'passwd?api.version=1' && $request['user'] === 'example' && $request['password'] === self::NEW_PASSWORD);
        $service = $this->service->fresh();
        $this->assertSame([self::NEW_PASSWORD, null, 'done'], [$service->password, $service->new_password, $service->provisioning_status]);
    }

    public function test_a_password_the_server_refuses_leaves_the_old_one_working(): void
    {
        Http::fake([self::WHM.'*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'The password is too weak.']])]);
        $this->actingAs($this->service->client, 'client');

        $this->put($this->page().'/password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        $this->assertSame(self::PASSWORD, $this->service->fresh()->password);
        $this->get($this->page())->assertSee('Your last password change didn', false)->assertSee(self::PASSWORD);
    }

    public function test_the_new_password_must_be_strong_and_typed_twice(): void
    {
        Http::fake();
        $this->actingAs($this->service->client, 'client');

        $this->put($this->page().'/password', ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put($this->page().'/password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'Different-pass9'])->assertSessionHasErrors('password');
        $this->put($this->page().'/password', ['password' => 'alllowercase123', 'password_confirmation' => 'alllowercase123'])->assertSessionHasErrors('password');

        Http::assertNothingSent();
    }

    public function test_only_running_accounts_of_the_client_can_be_changed(): void
    {
        Http::fake();
        $other = $this->serviceOn($this->server, $this->service->product);
        $this->actingAs($this->service->client, 'client');

        $this->post("/portal/services/{$other->id}/control-panel")->assertNotFound();
        $this->put("/portal/services/{$other->id}/password", ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertNotFound();

        $this->service->forceFill(['status' => ServiceStatus::Suspended])->save();
        $this->get($this->page())->assertDontSee('Log in to the control panel')->assertDontSee('Change the password');
        $this->post($this->page().'/control-panel')->assertForbidden();
        $this->put($this->page().'/password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_modules_say_what_they_can_do(): void
    {
        $modules = app(ModuleRegistry::class);

        $this->assertTrue($modules->find('cpanel')->canChangePassword($this->service));
        $this->assertFalse($modules->find('proxmox')->canChangePassword(Service::factory()->for(Product::factory()->create(['module' => 'proxmox', 'module_config' => ['type' => 'container']]))->create()));
    }

    private function page(): string
    {
        return "/portal/services/{$this->service->id}";
    }

    private function serviceOn(Server $server, Product $product): Service
    {
        $service = Service::factory()->for($product)->create(['label' => 'example.com']);
        $service->forceFill(['server_id' => $server->id, 'username' => 'example', 'password' => self::PASSWORD, 'provisioned_at' => now()])->save();

        return $service->fresh();
    }
}
