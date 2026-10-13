<?php

namespace Tests\Feature\Servers;

use App\Jobs\RunProvisioningAction;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Modules\Proxmox;
use App\Provisioning\ServerPicker;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ProxmoxModuleTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://pve.example.test:8006/api2/json/';

    private const VM = self::API.'nodes/pve1/qemu/105';

    private const TEMPLATE = 9000;

    private const NEW_ID = 105;

    private const CONFIG = '/config';

    private const TAKEN_IP = '203.0.113.10';

    private const FREE_IP = '203.0.113.11';

    private Server $server;

    private Service $service;

    /** @var array<int, array{node: string, status: string, template: int, config: array<string, mixed>}> the cluster's VMs */
    private array $vms = [];

    /** Whether tasks are still running when asked. */
    private bool $working = false;

    private string $exitStatus = 'OK';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->server = Server::factory()->create([
            'name' => 'pve', 'module' => 'proxmox', 'hostname' => 'pve.example.test', 'username' => 'root@pam!billing', 'api_token' => 'uuid-secret',
            'assigned_ips' => "203.0.113.10/24 203.0.113.1\n203.0.113.11/24 203.0.113.1",
        ]);
        $product = Product::factory()->create(['name' => 'VPS 2', 'module' => 'proxmox', 'module_config' => ['template' => (string) self::TEMPLATE, 'cores' => '2', 'memory' => '2048', 'disk_size' => '20']]);
        $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->service = Service::factory()->for($product)->for($client)->pending()->create(['label' => 'web1.example.com']);
        $this->service->forceFill(['server_id' => $this->server->id])->save();
        $this->vms = [self::TEMPLATE => ['node' => 'pve1', 'status' => 'stopped', 'template' => 1, 'config' => ['scsi0' => 'local-lvm:base-9000-disk-0,size=10G']]];

        Http::fake(fn (Request $request) => Http::response(['data' => $this->answer($request)]));
    }

    public function test_create_clones_the_template_comes_back_while_it_copies_then_sets_the_vm_up(): void
    {
        // Another VM already has the first address.
        Service::factory()->create(['server_id' => $this->server->id, 'provisioning_data' => ['vmid' => 104, 'ip' => self::TAKEN_IP]]);
        $this->working = true;

        $result = $this->module()->create($this->service);

        $this->assertFalse($result->ok);
        $this->assertSame(30, $result->retryAfter);
        $this->assertSame('Cloning the template into VM 105.', $result->message);
        $this->assertSame(['ip' => self::FREE_IP, 'vmid' => self::NEW_ID, 'node' => 'pve1'], collect($this->service->fresh()->provisioning_data)->except('clone_task')->all());
        Sleep::assertSleptTimes(9);
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'nodes/pve1/qemu/9000/clone'
            && $request->header('Authorization')[0] === 'PVEAPIToken=root@pam!billing=uuid-secret'
            && $request['newid'] === self::NEW_ID && $request['name'] === 'web1.example.com' && $request['full'] === 1
            && $request['description'] === "[service {$this->service->id}] Ada Lovelace");

        $this->working = false;
        $result = $this->module()->create($this->service->fresh());

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertSame('Created VM 105 on pve1 with the address 203.0.113.11.', $result->message);
        $config = $this->sent('PUT', self::VM.self::CONFIG);
        $this->assertSame(['cores' => 2, 'memory' => 2048, 'ciuser' => 'root', 'ipconfig0' => 'ip=203.0.113.11/24,gw=203.0.113.1'], collect($config)->except('cipassword')->all());
        $this->assertSame($this->service->fresh()->password, $config['cipassword']);
        $this->assertSame(['disk' => 'scsi0', 'size' => '20G'], $this->sent('PUT', self::VM.'/resize'));
        $this->assertSame('running', $this->vms[self::NEW_ID]['status']);
        $this->assertSame(['IP address' => self::FREE_IP, 'Username' => 'root'], $this->module()->loginDetails($this->service->fresh()));
    }

    public function test_the_job_waits_while_proxmox_works(): void
    {
        $this->service->forceFill(['server_id' => null])->save();
        $this->working = true;

        $job = (new RunProvisioningAction($this->service, 'create', 1))->withFakeQueueInteractions();
        $job->handle(app(ModuleRegistry::class), app(ServerPicker::class));

        $job->assertReleased(30);
        $service = $this->service->fresh();
        $this->assertSame($this->server->id, $service->server_id);
        $this->assertSame(0, $service->provisioning_finished);
        $this->assertSame(self::NEW_ID, $service->provisioning_data['vmid']);
    }

    public function test_a_failed_clone_is_forgotten_so_create_can_try_again(): void
    {
        $this->exitStatus = 'storage local-lvm is full';

        $result = $this->module()->create($this->service);

        $this->assertSame("Proxmox couldn't clone the template: storage local-lvm is full. Run create again to try with a new VM.", $result->message);
        $this->assertNull($this->service->fresh()->provisioning_data);
    }

    public function test_a_vm_that_is_not_the_services_is_left_alone(): void
    {
        $this->vms[self::NEW_ID] = ['node' => 'pve1', 'status' => 'running', 'template' => 0, 'config' => ['description' => '[service 999] Someone else']];
        $this->service->forceFill(['provisioning_data' => ['vmid' => self::NEW_ID, 'node' => 'pve1']])->save();

        $result = $this->module()->create($this->service);

        $this->assertSame("VM 105 on Proxmox isn't this service's VM, so it was left alone. Run create again to clone a new one.", $result->message);
        $this->assertNull($this->service->fresh()->provisioning_data);
        $this->assertNull($this->sent('PUT', self::VM.self::CONFIG));
    }

    public function test_terminate_stops_and_deletes_the_vm_and_frees_its_address(): void
    {
        $this->ownVm('running');

        $result = $this->module()->terminate($this->service);

        $this->assertSame('Removed VM 105 from pve1.', $result->message);
        $this->assertSame(['ip' => null], $result->data);
        $this->assertArrayNotHasKey(self::NEW_ID, $this->vms);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::VM.'?purge=1&destroy-unreferenced-disks=1');
        $this->assertSame('VM 105 was already gone.', $this->module()->terminate($this->service)->message);
    }

    public function test_suspend_shuts_the_vm_down_and_unsuspend_starts_it(): void
    {
        $this->ownVm('running');

        $this->assertSame('Shutting down VM 105. Proxmox turns it off after a minute if it hasn\'t shut down.', $this->module()->suspend($this->service)->message);
        $this->assertSame(['forceStop' => 1, 'timeout' => 60], $this->sent('POST', self::VM.'/status/shutdown'));

        $this->vms[self::NEW_ID]['status'] = 'stopped';
        $this->assertSame('Started VM 105.', $this->module()->unsuspend($this->service)->message);
    }

    public function test_change_package_resizes_and_restarts_a_running_vm(): void
    {
        $this->ownVm('running');

        $this->assertSame('Set VM 105 to 2 cores and 2048 MB of memory, and restarted it so they apply.', $this->module()->changePackage($this->service)->message);
        $this->assertSame(['cores' => 2, 'memory' => 2048], $this->sent('PUT', self::VM.self::CONFIG));
        $this->assertSame(['disk' => 'scsi0', 'size' => '20G'], $this->sent('PUT', self::VM.'/resize'));
        $this->assertNotNull($this->sent('POST', self::VM.'/status/reboot'));
    }

    public function test_change_password_goes_through_the_guest_agent_of_a_running_vm(): void
    {
        $this->ownVm('running');
        $this->service->forceFill(['username' => 'root', 'password' => 'New-pass-123'])->save();

        $this->assertTrue($this->module()->changePassword($this->service)->ok);
        $this->assertSame(['username' => 'root', 'password' => 'New-pass-123'], $this->sent('POST', self::VM.'/agent/set-user-password'));

        $this->vms[self::NEW_ID]['status'] = 'stopped';
        $this->assertSame('VM 105 is stopped. Start it, then run change password again.', $this->module()->changePassword($this->service)->message);
    }

    public function test_without_a_token_it_logs_in_for_a_ticket(): void
    {
        $this->server->update(['api_token' => null, 'username' => 'root@pam', 'password' => 'rootpass']);
        $this->ownVm('running');

        $this->assertTrue($this->module()->suspend($this->service->fresh())->ok);

        $this->assertSame(['username' => 'root@pam', 'password' => 'rootpass'], $this->sent('POST', self::API.'access/ticket'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'cluster/resources') && str_contains($request->header('Cookie')[0] ?? '', 'PVEAuthCookie=PVE:root@pam:TICKET'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/status/shutdown') && $request->hasHeader('CSRFPreventionToken', 'CSRF1'));
    }

    public function test_every_address_in_use_fails_create(): void
    {
        Service::factory()->create(['server_id' => $this->server->id, 'provisioning_data' => ['ip' => self::TAKEN_IP]]);
        Service::factory()->create(['server_id' => $this->server->id, 'provisioning_data' => ['ip' => self::FREE_IP]]);

        $this->assertSame('Every address in the IP pool of pve is in use. Add more to the server, then run create again.', $this->module()->create($this->service)->message);
        $this->assertNull($this->sent('POST', self::API.'nodes/pve1/qemu/9000/clone'));
    }

    public function test_test_connection_reports_the_version(): void
    {
        $this->assertSame('Connected to Proxmox VE 8.2.4.', $this->module()->testConnection($this->server)->message);
    }

    private function module(): Proxmox
    {
        return app(ModuleRegistry::class)->find('proxmox');
    }

    /** Gives the service a VM made by an earlier create. */
    private function ownVm(string $status): void
    {
        $this->vms[self::NEW_ID] = ['node' => 'pve1', 'status' => $status, 'template' => 0, 'config' => ['description' => "[service {$this->service->id}] Ada", 'scsi0' => 'local-lvm:vm-105-disk-0,size=10G']];
        $this->service->forceFill(['username' => 'root', 'provisioning_data' => ['vmid' => self::NEW_ID, 'node' => 'pve1', 'ip' => self::TAKEN_IP]])->save();
    }

    /** @return array<string, mixed>|null what was sent to $url, or null when nothing was */
    private function sent(string $method, string $url): ?array
    {
        $pair = Http::recorded(fn (Request $request) => $request->method() === $method && $request->url() === $url)->last();

        return $pair === null ? null : $pair[0]->data();
    }

    /** A small Proxmox: the cluster's VMs, cloning, power and tasks. */
    private function answer(Request $request): mixed
    {
        $path = str($request->url())->after('api2/json/')->before('?')->value();
        $task = fn (string $type) => "UPID:pve1:0001:0002:6700:{$type}:105:root@pam:";

        if (preg_match('#^nodes/pve1/qemu/(\d+)(/.*)?$#', $path, $match)) {
            return $this->vmAnswer((int) $match[1], $match[2] ?? '', $request, $task);
        }

        return match ($path) {
            'version' => ['version' => '8.2.4'],
            'access/ticket' => ['ticket' => 'PVE:root@pam:TICKET', 'CSRFPreventionToken' => 'CSRF1'],
            'cluster/nextid' => (string) self::NEW_ID,
            'cluster/resources' => collect($this->vms)->map(fn (array $vm, int $vmid) => ['vmid' => $vmid, 'type' => 'qemu', ...collect($vm)->except('config')->all()])->values()->all(),
            default => str_contains($path, '/tasks/') ? $this->taskStatus() : null,
        };
    }

    /** @return array<string, string> */
    private function taskStatus(): array
    {
        return $this->working ? ['status' => 'running'] : ['status' => 'stopped', 'exitstatus' => $this->exitStatus];
    }

    private function vmAnswer(int $vmid, string $action, Request $request, Closure $task): mixed
    {
        return match ($action) {
            '/clone' => $this->cloneVm($request, $task),
            self::CONFIG => $request->method() === 'GET' ? $this->vms[$vmid]['config'] : null,
            '/status/start' => tap($task('qmstart'), fn () => $this->vms[$vmid]['status'] = 'running'),
            '/status/stop' => tap($task('qmstop'), fn () => $this->vms[$vmid]['status'] = 'stopped'),
            '' => tap($task('qmdestroy'), function () use ($vmid) {
                unset($this->vms[$vmid]);
            }),
            default => $task('qmtask'),
        };
    }

    private function cloneVm(Request $request, Closure $task): string
    {
        $this->vms[(int) $request['newid']] = ['node' => 'pve1', 'status' => 'stopped', 'template' => 0, 'config' => [
            'description' => $request['description'],
            'scsi0' => "local-lvm:vm-{$request['newid']}-disk-0,size=10G",
        ]];

        return $task('qmclone');
    }
}
