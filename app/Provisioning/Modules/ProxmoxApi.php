<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * Proxmox VE's API (/api2/json), logged in with an API token, or a user and
 * password for a login ticket.
 */
final class ProxmoxApi extends ServerApi
{
    /** A task's exit status when it worked. */
    public const TASK_OK = 'OK';

    public const RUNNING = 'running';

    /** A virtual machine. */
    public const QEMU = 'qemu';

    /** A container. */
    public const LXC = 'lxc';

    /** Seconds before a later run checks a task that is still going. */
    public const COME_BACK_SECONDS = 30;

    /** The port Proxmox's API answers on. */
    public const PORT = 8006;

    /** Checks of a running task in one run, POLL_SECONDS apart, before coming back later. */
    private const POLLS = 10;

    private const POLL_SECONDS = 2;

    /** @var array{ticket: string, CSRFPreventionToken: string}|null the login ticket, without an API token */
    private ?array $ticket = null;

    /**
     * Calls the API and returns the reply's data.
     *
     * @param  array<string, mixed>  $params
     */
    public function call(string $method, string $path, array $params = []): mixed
    {
        $request = $this->authenticated($method !== 'get')->acceptJson();
        $url = "api2/json/{$path}";

        $response = match ($method) {
            'get' => $request->get($url, $params),
            'delete' => $request->delete($params === [] ? $url : $url.'?'.http_build_query($params)),
            'put' => $request->asForm()->put($url, $params),
            default => $request->asForm()->post($url, $params),
        };

        if (! $response->successful()) {
            throw $this->failure($response, $path);
        }

        return $response->json('data');
    }

    /**
     * A VM or container as the cluster lists it, wherever it was moved.
     *
     * @return array{node: string, status: string, template: bool, type: string}|null
     */
    public function vm(int $vmid): ?array
    {
        $vm = collect($this->call('get', 'cluster/resources', ['type' => 'vm']))
            ->first(fn (array $resource) => (int) ($resource['vmid'] ?? 0) === $vmid && in_array($resource['type'] ?? self::QEMU, [self::QEMU, self::LXC], true));

        return $vm === null ? null : [
            'node' => (string) $vm['node'],
            'status' => (string) ($vm['status'] ?? ''),
            'template' => (bool) ($vm['template'] ?? false),
            'type' => (string) ($vm['type'] ?? self::QEMU),
        ];
    }

    /** The API of a Proxmox server. */
    public static function for(Server $server): self
    {
        return new self($server, self::PORT);
    }

    /**
     * @return array{int, array{node: string, status: string, template: bool, type: string}} the service's VM id and the VM or container
     */
    public function serviceVm(Service $service): array
    {
        $vmid = $service->provisioning_data['vmid'] ?? throw new ModuleError('The service has nothing on Proxmox yet. Run create first.');

        return [(int) $vmid, $this->vm((int) $vmid) ?? throw new ModuleError("{$vmid} isn't on Proxmox.")];
    }

    /**
     * The cluster's name (null for a node on its own), the node the API
     * answers on, and each member node's address, sorted by name.
     *
     * @return array{name: ?string, node: string, members: array<string, string>}
     */
    public function cluster(): array
    {
        $status = collect($this->call('get', 'cluster/status'));
        $nodes = $status->filter(fn (array $entry) => ($entry['type'] ?? null) === 'node' && isset($entry['name']));
        $node = $nodes->first(fn (array $entry) => ! empty($entry['local']));

        return [
            'name' => $status->firstWhere('type', 'cluster')['name'] ?? null,
            'node' => isset($node['name']) ? (string) $node['name'] : $this->onlineNode(),
            'members' => $nodes->mapWithKeys(fn (array $entry) => [(string) $entry['name'] => (string) ($entry['ip'] ?? '')])->sortKeys()->all(),
        ];
    }

    /**
     * Whether two cluster() answers are the same cluster. Names aren't
     * unique, so the member nodes and their addresses must match too.
     *
     * @param  array{name: ?string, members: array<string, string>}  $one
     * @param  array{name: ?string, members: array<string, string>}  $other
     */
    public static function sameCluster(array $one, array $other): bool
    {
        return $one['name'] !== null && $one['name'] === $other['name'] && $one['members'] !== [] && $one['members'] === $other['members'];
    }

    /** The SHA-256 fingerprint of the node's web certificate, which another cluster checks when it connects. */
    public function fingerprint(string $node): ?string
    {
        $certificates = collect($this->call('get', "nodes/{$node}/certificates/info"));
        $certificate = $certificates->firstWhere('filename', 'pveproxy-ssl.pem') ?? $certificates->firstWhere('filename', 'pve-ssl.pem');

        return isset($certificate['fingerprint']) ? (string) $certificate['fingerprint'] : null;
    }

    /** The first node of the cluster that is online. */
    public function onlineNode(): string
    {
        $node = collect($this->call('get', 'nodes'))
            ->sortBy('node')
            ->first(fn (array $node) => ($node['status'] ?? 'online') === 'online');

        return isset($node['node']) ? (string) $node['node'] : throw new ModuleError('Proxmox has no node online.');
    }

    /**
     * Waits for a task: up to POLLS checks. Returns null while it is still
     * running, then its exit status, "OK" or an error.
     */
    public function await(mixed $task): ?string
    {
        if (! is_string($task) || ! str_starts_with($task, 'UPID:')) {
            return self::TASK_OK; // Finished without a task.
        }

        $node = explode(':', $task)[1];

        for ($poll = 0; $poll < self::POLLS; $poll++) {
            if ($poll > 0) {
                Sleep::for(self::POLL_SECONDS)->seconds();
            }

            $status = $this->call('get', "nodes/{$node}/tasks/".rawurlencode($task).'/status');

            if (($status['status'] ?? null) === 'stopped') {
                return (string) ($status['exitstatus'] ?? 'unknown');
            }
        }

        return null;
    }

    /** Starts a VM or container. Fails unless it is starting or running. */
    public function start(string $node, int $vmid, string $type = self::QEMU): void
    {
        $status = $this->await($this->call('post', self::path($node, $vmid, $type).'/status/start'));

        if ($status !== null && $status !== self::TASK_OK) {
            throw new ModuleError("Proxmox couldn't start VM {$vmid}: {$status}");
        }
    }

    /** The API path of a VM ("qemu") or container ("lxc"). */
    public static function path(string $node, int $vmid, string $type = self::QEMU): string
    {
        return "nodes/{$node}/{$type}/{$vmid}";
    }

    private function authenticated(bool $writes): PendingRequest
    {
        $request = $this->http();

        if (filled($this->server->api_token)) {
            return $request->withHeaders(['Authorization' => "PVEAPIToken={$this->server->username}={$this->server->api_token}"]);
        }

        // The ticket goes in a header rather than a cookie jar, which can't match an IPv6 host.
        $this->ticket ??= $this->logIn();
        $request->withHeaders(['Cookie' => "PVEAuthCookie={$this->ticket['ticket']}"]);

        return $writes ? $request->withHeaders(['CSRFPreventionToken' => $this->ticket['CSRFPreventionToken']]) : $request;
    }

    /** @return array{ticket: string, CSRFPreventionToken: string} */
    private function logIn(): array
    {
        $response = $this->http()->acceptJson()->asForm()
            ->post('api2/json/access/ticket', ['username' => (string) $this->server->username, 'password' => (string) $this->server->password]);

        if (! $response->successful() || ! is_string($response->json('data.ticket'))) {
            throw new ModuleError('Proxmox refused the login. Check the server\'s username and password, or use an API token.');
        }

        return $response->json('data');
    }

    private function failure(Response $response, string $path): ModuleError
    {
        $errors = $response->json('errors');
        $detail = trim((string) $response->json('message'));

        if ($detail === '' && is_array($errors)) {
            $detail = collect($errors)->map(fn ($message, $field) => "{$field}: ".trim((string) $message))->join('; ');
        }

        if ($detail === '') {
            $detail = trim($response->reason());
        }

        return new ModuleError("Proxmox: {$detail} (HTTP {$response->status()} on {$path}).");
    }

    /**
     * "VM 105" or "Container 105".
     *
     * @param  array{type: string}  $vm
     */
    public static function name(int $vmid, array $vm): string
    {
        return ($vm['type'] === self::LXC ? 'Container' : 'VM')." {$vmid}";
    }
}
