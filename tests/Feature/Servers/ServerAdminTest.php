<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Enums\StaffRole;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SERVERS = '/admin/servers';

    private const GROUPS = '/admin/server-groups';

    private const HOST = 'cp1.example.test';

    private const NAME = 'Shared 1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_staff_add_a_server_with_its_secrets_encrypted(): void
    {
        $this->get(self::SERVERS.'/create?module=cpanel')->assertOk()->assertSee('API token')->assertSee('WHM > Development > Manage API Tokens');

        $response = $this->post(self::SERVERS, $this->form(['api_token' => 'ABC123SECRET', 'password' => 'hunter22']));

        $server = Server::sole();
        $response->assertRedirect(self::SERVERS."/{$server->id}/edit");
        $this->assertSame('ABC123SECRET', $server->api_token);
        $this->assertSame('hunter22', $server->password);

        $raw = DB::table('servers')->first();
        $this->assertNotSame('ABC123SECRET', $raw->api_token);
        $this->assertSame('ABC123SECRET', Crypt::decryptString($raw->api_token));
        $this->assertArrayNotHasKey('api_token', $server->toArray());
        $this->get(self::SERVERS."/{$server->id}/edit")->assertOk()->assertDontSee('ABC123SECRET')->assertSee('Saved. Leave blank to keep it.');
    }

    public function test_blank_secrets_are_kept_and_ticked_ones_removed(): void
    {
        $server = Server::factory()->create(['api_token' => 'KEEP', 'password' => 'old-password']);

        $this->put(self::SERVERS."/{$server->id}", $this->form(['api_token' => '', 'password' => '', 'clear_password' => '1']))->assertRedirect(self::SERVERS);

        $server->refresh();
        $this->assertSame('KEEP', $server->api_token);
        $this->assertNull($server->password);
    }

    public function test_fields_the_module_does_not_use_are_left_empty(): void
    {
        $this->post(self::SERVERS, $this->form(['assigned_ips' => '203.0.113.10/24', 'ip_address' => '203.0.113.5']));

        $server = Server::sole();
        $this->assertNull($server->assigned_ips);
        $this->assertNull($server->ip_address);
    }

    public function test_server_details_are_checked(): void
    {
        $this->post(self::SERVERS, $this->form(['hostname' => 'not a host!', 'port' => 70000, 'username' => '']))
            ->assertSessionHasErrors(['hostname', 'port', 'username']);
        $this->post(self::SERVERS, $this->form(['module' => 'plesk', 'ip_address' => '']))->assertSessionHasErrors('ip_address');
        $this->post(self::SERVERS, $this->form(['module' => 'proxmox', 'assigned_ips' => "203.0.113.10/24 203.0.113.1\nnot-an-ip"]))
            ->assertSessionHasErrors(['assigned_ips' => 'Line 2 of the assigned IP addresses should be an address with an optional prefix length and gateway, like 203.0.113.10/24 203.0.113.1.']);
        $this->post(self::SERVERS, $this->form(['module' => 'webhook']))->assertSessionHasErrors('module');

        $this->assertSame(0, Server::count());
    }

    public function test_test_connection_reports_what_the_server_said(): void
    {
        $server = Server::factory()->create(['hostname' => self::HOST, 'username' => 'reseller', 'api_token' => 'TOKEN1']);
        Http::fake(['https://'.self::HOST.':2087/json-api/version*' => Http::sequence()
            ->push(['metadata' => ['result' => 1], 'data' => ['version' => '11.124.0.5']])
            ->push('Access denied', 403)]);

        $this->from(self::SERVERS."/{$server->id}/edit")->post(self::SERVERS."/{$server->id}/test")
            ->assertRedirect(self::SERVERS."/{$server->id}/edit")
            ->assertSessionHas('status', 'Connected to WHM 11.124.0.5.');
        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'whm reseller:TOKEN1');

        $this->post(self::SERVERS."/{$server->id}/test")
            ->assertSessionHas('connection_error', 'WHM refused the login (HTTP 403). Check the server\'s username, password and API token.');
    }

    public function test_a_server_with_accounts_on_it_cannot_be_deleted(): void
    {
        $server = Server::factory()->create();
        $service = Service::factory()->create(['server_id' => $server->id]);

        $this->delete(self::SERVERS."/{$server->id}")->assertStatus(422);

        $service->update(['status' => ServiceStatus::Terminated]);
        $this->delete(self::SERVERS."/{$server->id}")->assertRedirect(self::SERVERS);
        $this->assertModelMissing($server);
        $this->assertNull($service->fresh()->server_id);
    }

    public function test_the_servers_page_shows_how_full_each_server_is(): void
    {
        $server = Server::factory()->create(['name' => self::NAME, 'max_accounts' => 10]);
        Service::factory()->count(3)->create(['server_id' => $server->id]);
        Service::factory()->create(['server_id' => $server->id, 'status' => ServiceStatus::Cancelled]);

        $this->get(self::SERVERS)->assertOk()->assertSee(self::NAME)->assertSee('3 of 10')->assertSee('cPanel / WHM');
    }

    public function test_staff_group_servers_and_products_use_a_group(): void
    {
        $one = Server::factory()->create();
        $two = Server::factory()->create();

        $this->post(self::GROUPS, ['name' => 'EU shared', 'fill_type' => ServerGroup::FILL, 'servers' => [$one->id, $two->id]])->assertRedirect(self::SERVERS);
        $group = ServerGroup::sole();
        $this->assertSame([$one->id, $two->id], $group->servers->pluck('id')->sort()->values()->all());

        $this->put(self::GROUPS."/{$group->id}", ['name' => 'EU shared', 'fill_type' => ServerGroup::LEAST_USED, 'servers' => [$two->id]]);
        $group->refresh();
        $this->assertSame(ServerGroup::LEAST_USED, $group->fill_type);
        $this->assertSame([$two->id], $group->servers->pluck('id')->all());

        $this->post('/admin/products', $this->product('Starter', 'cpanel', ['package' => 'starter'], $group))->assertRedirect();
        $product = Product::where('name', 'Starter')->sole();
        $this->assertSame($group->id, $product->server_group_id);
        $this->get("/admin/products/{$product->id}/edit")->assertOk()->assertSee('EU shared (least used, 1 server)');

        $this->delete(self::GROUPS."/{$group->id}")->assertRedirect(self::SERVERS);
        $this->assertNull($product->fresh()->server_group_id);
    }

    public function test_products_without_a_server_module_have_no_server_group(): void
    {
        $group = ServerGroup::create(['name' => 'Group', 'fill_type' => ServerGroup::FILL]);

        $this->post('/admin/products', $this->product('Hook', 'webhook', ['url' => 'https://hooks.example.test'], $group))->assertRedirect();

        $this->assertNull(Product::where('name', 'Hook')->sole()->server_group_id);
    }

    public function test_only_staff_who_manage_settings_change_servers(): void
    {
        $server = Server::factory()->create();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]), 'web');

        $this->get(self::SERVERS)->assertForbidden();
        $this->post(self::SERVERS."/{$server->id}/test")->assertForbidden();
        $this->post(self::GROUPS, ['name' => 'x', 'fill_type' => ServerGroup::FILL])->assertForbidden();
    }

    /** @param  array<string, string>  $config */
    private function product(string $name, string $module, array $config, ServerGroup $group): array
    {
        return [
            'name' => $name, 'active' => '1', 'taxable' => '1', 'prices' => ['monthly' => ['price' => '10']],
            'module' => $module, 'module_config' => [$module => $config], 'server_group_id' => $group->id,
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(array $overrides = []): array
    {
        return [
            'name' => self::NAME,
            'module' => 'cpanel',
            'hostname' => self::HOST,
            'username' => 'root',
            'max_accounts' => 200,
            'secure' => '1',
            'verify_tls' => '1',
            'active' => '1',
            ...$overrides,
        ];
    }
}
