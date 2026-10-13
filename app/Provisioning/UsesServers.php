<?php

namespace App\Provisioning;

use App\Models\Server;
use App\Models\Service;

/**
 * For modules that set services up on servers staff add under Products >
 * Servers, such as a control panel. Before create runs, the service is put on
 * a server from its product's server group (or any server of the module, with
 * no group), which the module reads from $service->server.
 */
interface UsesServers
{
    /** The port used when a server doesn't set its own. */
    public function defaultPort(bool $secure): int;

    /**
     * The server fields the module uses besides the name, hostname, port and
     * account limit, with help for each. Keys are username, password,
     * api_token, ip_address and assigned_ips; a field marked required must be
     * filled in.
     *
     * @return array<string, array{help: string, required?: bool}>
     */
    public function serverFields(): array;

    /** Checks the server can be reached with its details, for the Test connection button. */
    public function testConnection(Server $server): ProvisioningResult;

    /** Whether a service needs a domain name (its label) to be created. */
    public function needsDomain(): bool;

    /**
     * What the client needs to log in, besides the password, shown on the
     * service page in the portal: label => value, e.g. the control panel's
     * address and the username. Addresses starting with http are linked.
     *
     * @return array<string, string>
     */
    public function loginDetails(Service $service): array;
}
