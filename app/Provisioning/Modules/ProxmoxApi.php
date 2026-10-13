<?php

namespace App\Provisioning\Modules;

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

    /** Seconds before a later run checks a task that is still going. */
    public const COME_BACK_SECONDS = 30;

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

    /** @return array{node: string, status: string, template: bool}|null the VM as the cluster lists it, wherever it was moved */
    public function vm(int $vmid): ?array
    {
        $vm = collect($this->call('get', 'cluster/resources', ['type' => 'vm']))
            ->first(fn (array $resource) => (int) ($resource['vmid'] ?? 0) === $vmid && ($resource['type'] ?? 'qemu') === 'qemu');

        return $vm === null ? null : ['node' => (string) $vm['node'], 'status' => (string) ($vm['status'] ?? ''), 'template' => (bool) ($vm['template'] ?? false)];
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

    /** Starts a VM. Fails unless it is starting or running. */
    public function start(string $node, int $vmid): void
    {
        $status = $this->await($this->call('post', self::path($node, $vmid).'/status/start'));

        if ($status !== null && $status !== self::TASK_OK) {
            throw new ModuleError("Proxmox couldn't start VM {$vmid}: {$status}");
        }
    }

    public static function path(string $node, int $vmid): string
    {
        return "nodes/{$node}/qemu/{$vmid}";
    }

    private function authenticated(bool $writes): PendingRequest
    {
        $request = $this->http();

        if (filled($this->server->api_token)) {
            return $request->withHeaders(['Authorization' => "PVEAPIToken={$this->server->username}={$this->server->api_token}"]);
        }

        $this->ticket ??= $this->logIn();
        $request->withCookies(['PVEAuthCookie' => $this->ticket['ticket']], $this->server->hostname);

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
}
