<?php

namespace Tests\Feature\Servers;

use App\Models\Server;
use App\Models\ServerOutage;
use App\Models\User;
use App\Monitoring\PortProbe;
use App\Monitoring\ServerMonitor;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ServerMonitorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, bool> which ports answer */
    private array $open = [2087 => true, 80 => true, 443 => true, 8006 => true];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create();
        $this->app->instance(PortProbe::class, new class($this->open) extends PortProbe
        {
            public function __construct(public array &$open) {}

            public function open(string $host, int $port, float $timeout = 5.0): ?int
            {
                return ($this->open[$port] ?? false) ? 12 : null;
            }
        });
        Http::fake(fn (Request $request) => str_contains($request->url(), ':8006/')
            ? Http::response(['data' => [['node' => 'pve1', 'status' => 'online', 'cpu' => 0.25, 'mem' => 4, 'maxmem' => 16, 'uptime' => 93600], ['node' => 'pve2', 'status' => 'offline']]])
            : Http::response(['metadata' => ['result' => 1], 'data' => ['one' => '0.52', 'five' => '0.48', 'fifteen' => '0.40']]));
    }

    public function test_a_server_that_answers_is_up_with_its_load(): void
    {
        $server = Server::factory()->create(['name' => 'cp1', 'monitor_ports' => '80, 443']);

        app(ServerMonitor::class)->check($server);

        $server->refresh();
        $this->assertSame([Server::UP, null, ['2087' => 12, '80' => 12, '443' => 12], ['Load' => '0.52, 0.48, 0.40']], [$server->status, $server->status_detail, $server->status_ports, $server->status_load]);
        Notification::assertNothingSent();
        $this->assertSame(100.0, $server->uptime());
    }

    public function test_a_server_goes_down_after_two_failed_checks_and_staff_hear_when_it_is_back(): void
    {
        $server = Server::factory()->create(['name' => 'cp1']);
        $monitor = app(ServerMonitor::class);
        $monitor->check($server);

        $this->open[2087] = false;
        $monitor->check($server->refresh());
        $this->assertSame([Server::UP, 1], [$server->refresh()->status, $server->status_misses]);
        Notification::assertNothingSent();

        $monitor->check($server);
        $server->refresh();
        $this->assertSame([Server::DOWN, 'Port 2087 doesn\'t answer.'], [$server->status, $server->status_detail]);
        $this->assertNull($server->status_load);
        $this->assertSame(Server::DOWN, ServerOutage::sole()->status);
        Notification::assertSentTo($this->admin, StaffAlert::class, fn (StaffAlert $alert) => $alert->subject === 'Server down: cp1');

        $this->actingAs($this->admin, 'web');
        $this->get('/admin')->assertSee('cp1 is down');
        $this->get('/admin/servers')->assertSee('Port 2087 doesn&#039;t answer.', false);

        $this->open[2087] = true;
        $monitor->check($server);
        $monitor->check($server->refresh());
        $this->assertSame(Server::UP, $server->refresh()->status);
        $this->assertNotNull(ServerOutage::sole()->ended_at);
        Notification::assertSentTo($this->admin, StaffAlert::class, fn (StaffAlert $alert) => $alert->subject === 'Server back up: cp1');
        // The activity log on the dashboard still mentions it; the warning is gone.
        $this->get('/admin')->assertViewHas('serverProblems', fn ($servers) => $servers->isEmpty());
    }

    public function test_the_status_changes_only_when_two_checks_in_a_row_agree(): void
    {
        $server = Server::factory()->create(['name' => 'cp1', 'monitor_ports' => '80']);
        $monitor = app(ServerMonitor::class);
        $monitor->check($server);

        // Down, then a warning: two different answers, so it stays up.
        $this->open[2087] = false;
        $monitor->check($server->refresh());
        $this->open = [...$this->open, 2087 => true, 80 => false];
        $monitor->check($server->refresh());
        $this->assertSame([Server::UP, Server::WARNING, 1], [$server->refresh()->status, $server->status_pending, $server->status_misses]);
        Notification::assertNothingSent();

        $monitor->check($server);
        $this->assertSame([Server::WARNING, null, 0], [$server->refresh()->status, $server->status_pending, $server->status_misses]);
    }

    public function test_a_closed_extra_port_or_an_offline_node_is_a_warning(): void
    {
        $cpanel = Server::factory()->create(['name' => 'cp1', 'monitor_ports' => '80 21']);
        $proxmox = Server::factory()->create(['name' => 'pve', 'module' => 'proxmox', 'hostname' => 'pve.example.test', 'username' => 'root@pam!billing']);
        $monitor = app(ServerMonitor::class);

        $monitor->checkAll();
        $monitor->checkAll();

        $this->assertSame([Server::WARNING, 'Port 21 doesn\'t answer.'], [$cpanel->refresh()->status, $cpanel->status_detail]);
        $proxmox->refresh();
        $this->assertSame([Server::WARNING, 'Node pve2 is offline.'], [$proxmox->status, $proxmox->status_detail]);
        $this->assertSame(['pve1' => 'CPU 25%, memory 25%, up 1d 2h', 'pve2' => 'offline'], $proxmox->status_load);
        // Warnings don't count against uptime.
        $this->assertSame(100.0, $proxmox->uptime());
    }

    public function test_staff_check_a_server_now_and_set_its_monitoring(): void
    {
        $server = Server::factory()->create(['name' => 'cp1']);
        $this->actingAs($this->admin, 'web');

        $this->post("/admin/servers/{$server->id}/check")->assertSessionHas('status', 'cp1: up.');
        $this->get("/admin/servers/{$server->id}/edit")->assertSee('Status monitoring')->assertSee('Check status');
        $this->put("/admin/servers/{$server->id}", ['name' => 'cp1', 'module' => 'cpanel', 'hostname' => $server->hostname, 'username' => 'root', 'max_accounts' => 200, 'monitor' => '0', 'monitor_ports' => '80, 443'])
            ->assertSessionHasNoErrors();
        $this->assertSame([false, [80, 443]], [$server->refresh()->monitor, $server->monitoredPorts()]);
        $this->put("/admin/servers/{$server->id}", ['name' => 'cp1', 'module' => 'cpanel', 'hostname' => $server->hostname, 'username' => 'root', 'max_accounts' => 200, 'monitor_ports' => 'http'])
            ->assertSessionHasErrors(['monitor_ports' => 'List the ports as numbers separated by commas, such as 80, 443, 21.']);

        $this->artisan('servers:check')->assertSuccessful();
        $this->get('/admin/servers')->assertSee('Off');
    }

    public function test_uptime_counts_the_time_a_server_was_down(): void
    {
        $this->travelTo(now()->startOfDay());
        $server = Server::factory()->create(['created_at' => now()->subDays(40), 'status' => Server::UP]);
        $server->forceFill(['status_checked_at' => now()])->save();
        $server->outages()->create(['status' => Server::DOWN, 'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)->addHours(3)]);
        $server->outages()->create(['status' => Server::DOWN, 'started_at' => now()->subDays(45), 'ended_at' => now()->subDays(44)]);

        // 3 hours out of 30 days.
        $this->assertSame(99.58, $server->uptime());
    }
}
