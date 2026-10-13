<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProductSettings;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;
use App\Provisioning\SingleSignOn;
use SimpleXMLElement;

/**
 * Plesk hosting, through the XML API (/enterprise/control/agent.php on port
 * 8443). Each service gets its own Plesk customer, whose login is the
 * service's username and password, and one subscription for its domain on
 * the product's service plan. Logs in as admin or a reseller, with the
 * password or a secret key.
 */
class Plesk extends ServerModule implements SingleSignOn
{
    private const PLAN = 'service plan';

    private const STATUS_ACTIVE = 0;

    private const STATUS_SUSPENDED = 16;

    private const OWNER_ID = 'owner-id';

    public function key(): string
    {
        return 'plesk';
    }

    public function label(): string
    {
        return 'Plesk';
    }

    public function configFields(): array
    {
        return [
            'plan' => ['label' => 'Service plan', 'required' => true, 'help' => 'The service plan new subscriptions use, named exactly as in Plesk. An upgrade or downgrade switches the subscription to the new product\'s plan.'],
        ];
    }

    public function defaultPort(bool $secure): int
    {
        return $secure ? 8443 : 8880;
    }

    public function serverFields(): array
    {
        return [
            'username' => ['help' => 'admin, or a reseller\'s login.', 'required' => true],
            'password' => ['help' => 'The login\'s password, unless you use a secret key.'],
            'api_token' => ['help' => 'A secret key from "plesk bin secret_key -c", used instead of the password.'],
            'ip_address' => ['help' => 'The IP address new subscriptions are hosted on.', 'required' => true],
        ];
    }

    public function loginDetails(Service $service): array
    {
        return array_filter([
            'Control panel' => $service->server?->address($service->server->secure ? 8443 : 8880),
            'Username' => $service->username,
        ]);
    }

    /**
     * A Plesk session for the service's customer, from the XML API's
     * create_session, opened through rsession_init.php. Plesk ties the
     * session to the client's IP address.
     */
    public function loginUrl(Service $service, string $ip): string
    {
        return $this->reach($service, function (Server $server) use ($service, $ip) {
            $session = PleskApi::result($this->packet($server, ['server' => ['create_session' => [
                'login' => ServerAccount::username($service),
                'data' => ['user_ip' => base64_encode($ip), 'source_server' => ''],
            ]]])->server->create_session);
            $id = trim((string) $session->id);

            return $id !== ''
                ? $server->url($this->defaultPort($server->secure)).'/enterprise/rsession_init.php?'.http_build_query(['PLESKSESSID' => $id])
                : throw new ModuleError('Plesk didn\'t return a session.');
        });
    }

    protected function checkConnection(Server $server): ProvisioningResult
    {
        $result = PleskApi::result($this->packet($server, ['server' => ['get' => ['stat' => []]]])->server->get);
        $version = (string) ($result->stat->version->plesk_version ?? '');

        return ProvisioningResult::ok('Connected to Plesk'.($version !== '' ? " {$version}" : '').'.');
    }

    protected function createAccount(Service $service, Server $server): ProvisioningResult
    {
        $plan = ProductSettings::of($service)->required('plan', self::PLAN);
        $ip = $server->ip_address ?: throw new ModuleError("Set the IP address of the server {$server->name} first.");

        // An earlier run whose answer was lost may have made the subscription already.
        $customerId = $this->customerId($service, $server);
        $subscription = PleskApi::find($this->packet($server, ['webspace' => ['get' => [
            'filter' => ['name' => $service->label],
            'dataset' => ['gen_info' => []],
        ]]])->webspace->get);

        if ($subscription !== null && ! $this->ownedBy($subscription->data->gen_info, $customerId, $service->username)) {
            return ProvisioningResult::failed("{$service->label} is already a subscription of another customer on {$server->name}.");
        }

        $subscriptionId = $subscription !== null ? (int) $subscription->id : $this->addSubscription($service, $server, $customerId, $ip, $plan);

        return ProvisioningResult::ok(
            ($subscription !== null ? 'The subscription was already on' : 'Created the subscription on')." {$server->name} for the customer {$service->username}.",
            ['plesk_customer_id' => $customerId, 'plesk_subscription_id' => $subscriptionId],
        );
    }

    protected function suspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $this->setStatus($service, $server, self::STATUS_SUSPENDED);

        return ProvisioningResult::ok("Suspended the subscription {$service->label}.");
    }

    protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $this->setStatus($service, $server, self::STATUS_ACTIVE);

        return ProvisioningResult::ok("Unsuspended the subscription {$service->label}.");
    }

    /** Deletes the service's customer, and with it the subscription. */
    protected function terminateAccount(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $deleted = PleskApi::find($this->packet($server, ['customer' => ['del' => ['filter' => ['login' => $username]]]])->customer->del);

        return ProvisioningResult::ok($deleted !== null
            ? "Removed the customer {$username} and their subscription from {$server->name}."
            : "The customer {$username} was already gone from {$server->name}.");
    }

    protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult
    {
        $plan = ProductSettings::of($service)->required('plan', self::PLAN);
        $found = PleskApi::find($this->packet($server, ['service-plan' => ['get' => ['filter' => ['name' => $plan]]]])->{'service-plan'}->get)
            ?? throw new ModuleError("Plesk has no service plan called {$plan}.");

        PleskApi::result($this->packet($server, ['webspace' => ['switch-subscription' => [
            'filter' => $this->subscriptionFilter($service),
            'plan-guid' => (string) $found->guid,
        ]]])->webspace->{'switch-subscription'});

        return ProvisioningResult::ok("Switched the subscription {$service->label} to the plan {$plan}.");
    }

    /** Sets the password of the customer's login and of the subscription's FTP user. */
    protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $packet = $this->packet($server, [
            'customer' => ['set' => ['filter' => ['login' => $username], 'values' => ['gen_info' => ['passwd' => $service->password]]]],
            'webspace' => ['set' => ['filter' => $this->subscriptionFilter($service), 'values' => ['hosting' => ['vrt_hst' => [
                'property' => [['name' => 'ftp_password', 'value' => $service->password]],
            ]]]]],
        ]);
        PleskApi::result($packet->customer->set);
        PleskApi::result($packet->webspace->set);

        return ProvisioningResult::ok("Changed the password of {$username}.");
    }

    /** Whether a subscription's details name the customer as its owner (Plesk may leave the owner out). */
    private function ownedBy(?SimpleXMLElement $info, int $customerId, string $login): bool
    {
        return match (true) {
            $info === null => true,
            isset($info->{'owner-login'}) => (string) $info->{'owner-login'} === $login,
            isset($info->{self::OWNER_ID}) => (int) $info->{self::OWNER_ID} === $customerId,
            default => true,
        };
    }

    /** The id of the service's customer, added unless an earlier run whose answer was lost already did. */
    private function customerId(Service $service, Server $server): int
    {
        $customer = PleskApi::find($this->packet($server, ['customer' => ['get' => [
            'filter' => ['login' => $service->username],
            'dataset' => ['gen_info' => []],
        ]]])->customer->get);

        if ($customer !== null) {
            return (int) $customer->id;
        }

        $client = $service->client;
        $info = array_filter([
            'cname' => $client->company,
            'pname' => $client->fullName(),
            'login' => $service->username,
            'passwd' => $service->password,
            'email' => $client->email,
        ], filled(...));

        return (int) PleskApi::result($this->packet($server, ['customer' => ['add' => ['gen_info' => $info]]])->customer->add)->id;
    }

    private function addSubscription(Service $service, Server $server, int $customerId, string $ip, string $plan): int
    {
        // Plesk reads the nodes in this order.
        $packet = $this->packet($server, ['webspace' => ['add' => [
            'gen_setup' => ['name' => $service->label, self::OWNER_ID => $customerId, 'htype' => 'vrt_hst', 'ip_address' => $ip, 'status' => self::STATUS_ACTIVE],
            'hosting' => ['vrt_hst' => [
                'property' => [['name' => 'ftp_login', 'value' => $service->username], ['name' => 'ftp_password', 'value' => $service->password]],
                'ip_address' => $ip,
            ]],
            'plan-name' => $plan,
        ]]]);

        return (int) PleskApi::result($packet->webspace->add)->id;
    }

    private function setStatus(Service $service, Server $server, int $status): void
    {
        PleskApi::result($this->packet($server, ['webspace' => ['set' => [
            'filter' => $this->subscriptionFilter($service),
            'values' => ['gen_setup' => ['status' => $status]],
        ]]])->webspace->set);
    }

    /** @return array<string, int|string> the subscription by the id saved at create, or by domain */
    private function subscriptionFilter(Service $service): array
    {
        $id = $service->provisioning_data['plesk_subscription_id'] ?? null;

        return $id !== null ? ['id' => (int) $id] : ['name' => (string) $service->label];
    }

    /**
     * Sends one XML API packet to the server.
     *
     * @param  array<string, mixed>  $operations
     */
    private function packet(Server $server, array $operations): SimpleXMLElement
    {
        return (new PleskApi($server, $this->defaultPort($server->secure)))->send($operations);
    }
}
