<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Provisioning\ModuleError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A client for a server's HTTP API, at the server's address and port and
 * with its certificate setting. Each module's client adds its login and
 * reads the answers.
 */
abstract class ServerApi
{
    /** Seconds to wait for an answer; creating an account can be slow. */
    private const TIMEOUT = 50;

    /** @param  int  $defaultPort  the port used when the server doesn't set its own */
    public function __construct(protected readonly Server $server, private readonly int $defaultPort) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->server->url($this->defaultPort))
            ->withOptions(['verify' => $this->server->verify_tls])
            ->withUserAgent(config('app.name').' provisioning')
            ->connectTimeout(10)
            ->timeout(self::TIMEOUT);
    }

    /** The error for an HTTP answer that isn't a usable reply from $system to $what. */
    protected function unexpected(string $system, Response $response, string $what): ModuleError
    {
        $status = $response->status();

        return new ModuleError(in_array($status, [401, 403], true)
            ? "{$system} refused the login (HTTP {$status}). Check the server's username, password and API token."
            : "Unexpected answer from {$system} to {$what} (HTTP {$status}).");
    }
}
