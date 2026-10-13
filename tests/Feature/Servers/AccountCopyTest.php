<?php

namespace Tests\Feature\Servers;

use App\Jobs\RunProvisioningAction;
use App\Models\AccountMove;
use App\Models\Activity;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\AccountMover;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use App\Provisioning\ServerPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AccountCopyTest extends TestCase
{
    use RefreshDatabase;

    private const CP1 = 'cp1.example.test';

    private const CP2 = 'cp2.example.test';

    private const PVE_A = 'pve-a.example.test';

    private const PVE_B = 'pve-b.example.test';

    private const SESSION = 'cp1example20261013';

    private const DOMAIN = 'example.com';

    private const PVE_API = 'https://'.self::PVE_A.':8006/api2/json/nodes/pve1/qemu/105';

    private const VM_IP = '203.0.113.10';

    private User $staff;

    /** @var array<string, array<string, string>> cPanel accounts on each server: host => [username => domain] */
    private array $accounts = [];

    /** What WHM says of the transfer session. */
    private string $transferState = 'RUNNING';

    /** @var array<string, array{cluster: ?string, node: string}> each Proxmox host's cluster and node */
    private array $clusters = [];

    /** @var array<string, array<int, array{node: string, status: string}>> VMs each Proxmox host's cluster lists */
    private array $vms = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Sleep::fake();
        $this->staff = User::factory()->create();
        $this->accounts = [self::CP1 => ['example' => self::DOMAIN], self::CP2 => []];

        Http::fake(fn (Request $request) => str_contains($request->url(), ':8006/') ? $this->proxmox($request) : $this->whm($request));
    }

    public function test_a_cpanel_move_copies_the_account_with_whms_transfer_tool(): void
    {
        [$service, , $cp2] = $this->cpanelService();
        $this->actingAs($this->staff, 'web');
        $page = "/admin/services/{$service->id}";

        $this->get($page)->assertSee('Copy the account with its data automatically (cPanel transfer)');
        $this->post("{$page}/account-moves", ['to_server_id' => $cp2->id, 'copy_data' => '1'])
            ->assertSessionHas('status', 'Copying the account to cp2. The old one stays until you finish the move.');

        // The new server pulls the account from the old one over SSH as root.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), self::CP2.':2087/json-api/create_remote_root_transfer_session')
            && $request['host'] === '198.51.100.1' && $request['user'] === 'root' && $request['password'] === 'root-pass' && $request['remote_server_type'] === 'cpanel');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'enqueue_transfer_item')
            && $request['transfer_session_id'] === self::SESSION && $request['module'] === 'AccountRemoteRoot' && $request['user'] === 'example' && $request['localuser'] === 'example');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'start_transfer_session'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'createacct'));
        $service = $service->fresh();
        $this->assertSame(['pending', Provisioner::COPY], [$service->provisioning_status, $service->provisioning_action]);
        $this->get($page)->assertSee('copying the account to the new server')->assertSee('The module is copying the account and its data');

        // A later run finds the transfer done.
        $this->transferState = 'COMPLETED';
        $this->accounts[self::CP2]['example'] = self::DOMAIN;
        $this->runAgain($service, Provisioner::COPY);

        $service = $service->fresh();
        $move = AccountMove::sole();
        $this->assertSame(['done', AccountMove::COPYING, true], [$service->provisioning_status, $move->status, $move->copy_data]);
        $this->assertArrayNotHasKey('transfer_session', $service->provisioning_data ?? []);
        $this->get($page)->assertSee('copied, waiting for staff to check it and finish')->assertSee('Finish the move: remove the old account');

        $this->post("/admin/account-moves/{$move->id}/finish");

        $this->assertSame([[], ['example' => self::DOMAIN]], [$this->accounts[self::CP1], $this->accounts[self::CP2]]);
        $this->assertSame(AccountMove::DONE, $move->fresh()->status);
    }

    public function test_a_failed_transfer_says_so_and_can_be_cancelled(): void
    {
        [$service, , $cp2] = $this->cpanelService();
        $this->actingAs($this->staff, 'web');
        $this->post("/admin/services/{$service->id}/account-moves", ['to_server_id' => $cp2->id, 'copy_data' => '1']);

        $this->transferState = 'FAILED';
        $this->runAgain($service->fresh(), Provisioner::COPY);

        $service = $service->fresh();
        $this->assertSame('failed', $service->provisioning_status);
        $this->assertStringContainsString("WHM's transfer of example to cp2 ended failed (session ".self::SESSION.')', $service->provisioning_error);
        $this->get("/admin/services/{$service->id}")->assertSee('Cancel the move')->assertSee('<option value="copy_account" selected>Copy account</option>', false);

        $this->delete('/admin/account-moves/'.AccountMove::sole()->id);
        $this->assertSame(self::CP1, $service->fresh()->server->hostname);
    }

    public function test_a_cpanel_copy_needs_the_old_servers_root_password(): void
    {
        [$service, $cp1, $cp2] = $this->cpanelService();
        $cp1->update(['username' => 'reseller1']);
        $this->actingAs($this->staff, 'web');

        $this->get("/admin/services/{$service->id}")->assertSee("Not to cp2: WHM's transfer tool logs in to cp1 as root, and cp1 is set up with reseller1.");
        $this->post("/admin/services/{$service->id}/account-moves", ['to_server_id' => $cp2->id, 'copy_data' => '1'])
            ->assertSessionHasErrors(['move_server' => "WHM's transfer tool logs in to cp1 as root, and cp1 is set up with reseller1."]);

        $cp1->update(['username' => 'root', 'password' => null]);
        $this->post("/admin/services/{$service->id}/account-moves", ['to_server_id' => $cp2->id, 'copy_data' => '1'])
            ->assertSessionHasErrors(['move_server' => 'cp1 has no root password saved, which WHM\'s transfer tool logs in to it with over SSH.']);
        $this->assertSame(0, AccountMove::count());
    }

    public function test_copy_isnt_offered_to_run_by_hand_without_a_copying_move(): void
    {
        [$service] = $this->cpanelService();
        $this->actingAs($this->staff, 'web');

        $this->get("/admin/services/{$service->id}")->assertDontSee('<option value="copy_account"', false);
        $this->post("/admin/services/{$service->id}/provision", ['action' => Provisioner::COPY])->assertStatus(422);
    }

    public function test_a_proxmox_vm_moved_inside_its_cluster_is_migrated_with_nothing_left_to_remove(): void
    {
        [$service, $to] = $this->proxmoxService(sameCluster: true);

        app(AccountMover::class)->toServer($service, $to, false, $this->staff, copy: true);

        Http::assertSent(fn (Request $request) => $request->url() === self::PVE_API.'/migrate'
            && $request['target'] === 'pve2' && $request['online'] === 1 && $request['with-local-disks'] === 1);
        $move = AccountMove::sole();
        $this->assertSame([AccountMove::DONE, false], [$move->status, $move->remove_old]);
        $service = $service->fresh();
        $this->assertSame(['vmid' => 105, 'node' => 'pve2', 'ip' => self::VM_IP], $service->provisioning_data);
        $this->assertSame([$to->id, 'done'], [$service->server_id, $service->provisioning_status]);
        $this->assertSame('Moved VPS - vps1.example.com; the account itself was moved, so there was no old one to remove.', Activity::latest('id')->value('description'));
        // The VM itself moved, so nothing was deleted.
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_a_proxmox_vm_moved_to_another_cluster_is_copied_by_remote_migration(): void
    {
        [$service, $to] = $this->proxmoxService(sameCluster: false);

        app(AccountMover::class)->toServer($service, $to, false, $this->staff, copy: true);

        Http::assertSent(fn (Request $request) => $request->url() === self::PVE_API.'/remote_migrate'
            && $request['target-endpoint'] === 'host='.self::PVE_B.',apitoken=PVEAPIToken=root@pam!billing=token-b,fingerprint=AA:BB:CC,port=8006'
            && $request['target-vmid'] === 200 && $request['target-storage'] === 'local-lvm' && $request['target-bridge'] === 'vmbr0'
            && $request['online'] === 1 && $request['delete'] === 0);
        $move = AccountMove::sole();
        $this->assertSame([AccountMove::COPYING, true], [$move->status, $move->remove_old]);
        $this->assertSame(['vmid' => 200, 'node' => 'pve9', 'ip' => self::VM_IP], $service->fresh()->provisioning_data);

        // Finishing removes the old, stopped VM from the old cluster.
        $this->vms[self::PVE_A][105]['status'] = 'stopped';
        app(AccountMover::class)->finish($move);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_starts_with($request->url(), self::PVE_API));
        $this->assertSame(AccountMove::DONE, $move->fresh()->status);
    }

    public function test_remote_migration_needs_an_api_token_on_the_new_server(): void
    {
        [$service, $to] = $this->proxmoxService(sameCluster: false);
        $to->update(['api_token' => null, 'username' => 'root@pam', 'password' => 'secret']);

        app(AccountMover::class)->toServer($service, $to, false, $this->staff, copy: true);

        $this->assertStringContainsString('pve-b is in another Proxmox cluster. Copying there uses Proxmox\'s remote migration, which needs an API token on pve-b.', $service->fresh()->provisioning_error);
        $this->assertSame(AccountMove::CREATING, AccountMove::sole()->status);
    }

    /** @return array{Service, Server, Server} */
    private function cpanelService(): array
    {
        $product = Product::factory()->create(['name' => 'Starter', 'module' => 'cpanel', 'module_config' => ['package' => 'basic']]);
        $cp1 = Server::factory()->create(['name' => 'cp1', 'hostname' => self::CP1, 'ip_address' => '198.51.100.1', 'username' => 'root', 'password' => 'root-pass']);
        $cp2 = Server::factory()->create(['name' => 'cp2', 'hostname' => self::CP2]);
        $service = Service::factory()->for($product)->create(['label' => self::DOMAIN]);
        $service->forceFill(['server_id' => $cp1->id, 'username' => 'example', 'password' => 'Pass-word1', 'provisioned_at' => now(), 'provisioning_status' => 'done'])->save();

        return [$service, $cp1, $cp2];
    }

    /** @return array{Service, Server} */
    private function proxmoxService(bool $sameCluster): array
    {
        $product = Product::factory()->create(['name' => 'VPS', 'module' => 'proxmox', 'module_config' => ['template' => '9000', 'cores' => '2', 'memory' => '2048']]);
        $from = Server::factory()->create(['name' => 'pve-a', 'module' => 'proxmox', 'hostname' => self::PVE_A, 'username' => 'root@pam!billing', 'api_token' => 'token-a']);
        $to = Server::factory()->create(['name' => 'pve-b', 'module' => 'proxmox', 'hostname' => self::PVE_B, 'username' => 'root@pam!billing', 'api_token' => 'token-b']);
        $service = Service::factory()->for($product)->create(['label' => 'vps1.example.com']);
        $service->forceFill(['server_id' => $from->id, 'username' => 'root', 'password' => 'Pass-word1', 'provisioned_at' => now(), 'provisioning_status' => 'done', 'provisioning_data' => ['vmid' => 105, 'node' => 'pve1', 'ip' => self::VM_IP]])->save();

        $this->clusters = $sameCluster
            ? [self::PVE_A => ['cluster' => 'prod', 'node' => 'pve1'], self::PVE_B => ['cluster' => 'prod', 'node' => 'pve2']]
            : [self::PVE_A => ['cluster' => 'old', 'node' => 'pve1'], self::PVE_B => ['cluster' => null, 'node' => 'pve9']];
        $this->vms = [self::PVE_A => [105 => ['node' => 'pve1', 'status' => 'running']], self::PVE_B => $sameCluster ? [105 => ['node' => 'pve1', 'status' => 'running']] : []];

        return [$service, $to];
    }

    private function runAgain(Service $service, string $action): void
    {
        (new RunProvisioningAction($service, $action, $service->provisioning_queued))->handle(app(ModuleRegistry::class), app(ServerPicker::class));
    }

    /** A WHM that keeps each server's accounts and runs one transfer session. */
    private function whm(Request $request): mixed
    {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $function = str($request->url())->after('json-api/')->before('?')->value();
        $ok = fn (array $data = []) => ['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data];

        return Http::response(match ($function) {
            'listaccts' => $ok(['acct' => collect($this->accounts[$host])->map(fn ($domain, $user) => ['user' => $user, 'domain' => $domain])->values()->all()]),
            'create_remote_root_transfer_session' => $ok(['transfer_session_id' => self::SESSION]),
            'get_transfer_session_state' => $ok(['state_name' => $this->transferState]),
            'removeacct' => tap($ok(), function () use ($host, $request) {
                unset($this->accounts[$host][$request['username']]);
            }),
            default => $ok(),
        });
    }

    /** Two Proxmox hosts, each with its cluster, node and VMs. */
    private function proxmox(Request $request): mixed
    {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $path = str($request->url())->after('api2/json/')->before('?')->value();
        $task = 'UPID:pve1:0001:0002:6700:qmigrate:105:root@pam:';

        $data = match (true) {
            $path === 'cluster/status' => array_values(array_filter([
                $this->clusters[$host]['cluster'] ? ['type' => 'cluster', 'name' => $this->clusters[$host]['cluster']] : null,
                ['type' => 'node', 'name' => $this->clusters[$host]['node'], 'local' => 1],
            ])),
            $path === 'cluster/resources' => collect($this->vms[$host])->map(fn (array $vm, int $vmid) => ['vmid' => $vmid, 'type' => 'qemu', 'template' => 0, ...$vm])->values()->all(),
            $path === 'cluster/nextid' => '200',
            $path === 'access/ticket' => ['ticket' => 'PVE:root@pam:TICKET', 'CSRFPreventionToken' => 'CSRF1'],
            str_ends_with($path, '/certificates/info') => [['filename' => 'pve-ssl.pem', 'fingerprint' => 'XX'], ['filename' => 'pveproxy-ssl.pem', 'fingerprint' => 'AA:BB:CC']],
            str_contains($path, '/tasks/') => ['status' => 'stopped', 'exitstatus' => 'OK'],
            str_ends_with($path, '/migrate'), str_ends_with($path, '/remote_migrate'), str_ends_with($path, '/status/stop') => $task,
            $request->method() === 'DELETE' => tap($task, function () use ($host) {
                unset($this->vms[$host][105]);
            }),
            default => null,
        };

        return Http::response(['data' => $data]);
    }
}
