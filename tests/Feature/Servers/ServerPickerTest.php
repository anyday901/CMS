<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\ServerPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerPickerTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::factory()->create(['module' => 'cpanel', 'module_config' => ['package' => 'basic']]);
    }

    public function test_without_a_group_the_least_used_server_of_the_module_is_picked(): void
    {
        $busy = $this->server('busy', 10, accounts: 5);
        $quiet = $this->server('quiet', 100, accounts: 20);
        $this->server('other module', 100, module: 'plesk');
        $this->server('off', 100, active: false);

        // 20 of 100 is less full than 5 of 10.
        $this->assertTrue($this->pick()->is($quiet));

        $quiet->update(['max_accounts' => 40]);
        $this->assertTrue($this->pick()->is($busy), 'Equally full: the one with fewer accounts wins.');
    }

    public function test_least_used_spreads_new_services_across_the_group(): void
    {
        $group = $this->group(ServerGroup::LEAST_USED);
        $one = $this->server('one', 10, group: $group);
        $two = $this->server('two', 10, group: $group);
        $this->server('outside the group', 1000);

        $picked = [];

        for ($i = 0; $i < 4; $i++) {
            $server = $this->pick();
            $picked[] = $server->name;
            Service::factory()->for($this->product)->create(['server_id' => $server->id]);
        }

        $this->assertSame(['one', 'two', 'one', 'two'], $picked);
        $this->assertSame(2, $one->accounts()->count());
        $this->assertSame(2, $two->accounts()->count());
    }

    public function test_fill_up_fills_one_server_before_the_next(): void
    {
        $group = $this->group(ServerGroup::FILL);
        $first = $this->server('first', 2, group: $group);
        $second = $this->server('second', 10, accounts: 1, group: $group);

        // The fullest server with room goes first: 1 of 10 beats an empty one.
        $this->assertTrue($this->pick()->is($second));

        $second->update(['max_accounts' => 1]);
        $this->assertTrue($this->pick()->is($first));
    }

    public function test_ended_services_free_their_room(): void
    {
        $server = $this->server('small', 1, accounts: 1);
        $this->assertNull($this->pick());

        $server->accounts()->first()->update(['status' => ServiceStatus::Terminated]);
        $this->assertTrue($this->pick()->is($server));
    }

    public function test_create_fails_with_what_to_do_when_no_server_has_room(): void
    {
        $service = Service::factory()->for($this->product)->pending()->create();
        $module = app(ModuleRegistry::class)->find('cpanel');

        $this->assertSame(
            'No active cPanel / WHM server has room for another account. Add one under Products > Servers, then run create again.',
            app(ServerPicker::class)->assign($service, $module),
        );

        $group = $this->group(ServerGroup::FILL);
        $this->product->update(['server_group_id' => $group->id]);
        $this->server('full', 1, accounts: 1, group: $group);
        $this->assertStringContainsString('in the group EU has room', app(ServerPicker::class)->assign($service->fresh(), $module));
        $this->assertNull($service->fresh()->server_id);
    }

    public function test_a_service_keeps_the_server_it_has(): void
    {
        $chosen = $this->server('chosen', 1, accounts: 1);
        $this->server('empty', 100);
        $service = Service::factory()->for($this->product)->pending()->create(['server_id' => $chosen->id]);

        $this->assertNull(app(ServerPicker::class)->assign($service, app(ModuleRegistry::class)->find('cpanel')));
        $this->assertSame($chosen->id, $service->fresh()->server_id);
    }

    private function pick(): ?Server
    {
        return app(ServerPicker::class)->pick($this->product->fresh(), 'cpanel');
    }

    private function group(string $fill): ServerGroup
    {
        $group = ServerGroup::create(['name' => 'EU', 'fill_type' => $fill]);
        $this->product->update(['server_group_id' => $group->id]);

        return $group;
    }

    private function server(string $name, int $max, int $accounts = 0, ?ServerGroup $group = null, string $module = 'cpanel', bool $active = true): Server
    {
        $server = Server::factory()->create(['name' => $name, 'max_accounts' => $max, 'module' => $module, 'active' => $active]);
        Service::factory()->count($accounts)->for($this->product)->create(['server_id' => $server->id]);
        $group?->servers()->attach($server);

        return $server;
    }
}
