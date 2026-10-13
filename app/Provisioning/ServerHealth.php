<?php

namespace App\Provisioning;

/** How a server says it is doing, for server status monitoring. */
final readonly class ServerHealth
{
    /**
     * @param  array<string, string>  $facts  shown on the servers page, e.g. "Load" => "0.52, 0.48, 0.40"
     * @param  list<string>  $problems  anything wrong, which puts the server in warning
     */
    public function __construct(public array $facts = [], public array $problems = []) {}
}
