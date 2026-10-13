<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use DOMDocument;
use DOMNode;
use SimpleXMLElement;

/**
 * Plesk hosting, through the XML API (/enterprise/control/agent.php on port
 * 8443). Each service gets its own Plesk customer, whose login is the
 * service's username and password, and one subscription for its domain on
 * the product's service plan. Logs in as admin or a reseller, with the
 * password or a secret key.
 */
class Plesk extends ServerModule
{
    private const PLESK = 'Plesk';

    private const STATUS_ACTIVE = 0;

    private const STATUS_SUSPENDED = 16;

    /** Plesk's error code for an object that doesn't exist. */
    private const NOT_FOUND = '1013';

    public function key(): string
    {
        return 'plesk';
    }

    public function label(): string
    {
        return self::PLESK;
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

    protected function checkConnection(Server $server): ProvisioningResult
    {
        $result = $this->result($this->packet($server, ['server' => ['get' => ['stat' => []]]])->server->get);
        $version = (string) ($result->stat->version->plesk_version ?? '');

        return ProvisioningResult::ok('Connected to Plesk'.($version !== '' ? " {$version}" : '').'.');
    }

    protected function createAccount(Service $service, Server $server): ProvisioningResult
    {
        $plan = $this->requiredSetting($service, 'plan', 'service plan');
        $ip = $server->ip_address ?: throw new ModuleError("Set the IP address of the server {$server->name} first.");

        // An earlier run whose answer was lost may have made the customer or the subscription already.
        $customerId = $this->customerId($server, $service->username) ?? $this->addCustomer($service, $server);
        $subscription = $this->find($this->packet($server, ['webspace' => ['get' => [
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
        $username = $this->username($service);
        $deleted = $this->find($this->packet($server, ['customer' => ['del' => ['filter' => ['login' => $username]]]])->customer->del);

        return ProvisioningResult::ok($deleted !== null
            ? "Removed the customer {$username} and their subscription from {$server->name}."
            : "The customer {$username} was already gone from {$server->name}.");
    }

    protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult
    {
        $plan = $this->requiredSetting($service, 'plan', 'service plan');
        $found = $this->find($this->packet($server, ['service-plan' => ['get' => ['filter' => ['name' => $plan]]]])->{'service-plan'}->get)
            ?? throw new ModuleError("Plesk has no service plan called {$plan}.");

        $this->result($this->packet($server, ['webspace' => ['switch-subscription' => [
            'filter' => $this->subscriptionFilter($service),
            'plan-guid' => (string) $found->guid,
        ]]])->webspace->{'switch-subscription'});

        return ProvisioningResult::ok("Switched the subscription {$service->label} to the plan {$plan}.");
    }

    /** Sets the password of the customer's login and of the subscription's FTP user. */
    protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult
    {
        $username = $this->username($service);
        $packet = $this->packet($server, [
            'customer' => ['set' => ['filter' => ['login' => $username], 'values' => ['gen_info' => ['passwd' => $service->password]]]],
            'webspace' => ['set' => ['filter' => $this->subscriptionFilter($service), 'values' => ['hosting' => ['vrt_hst' => [
                'property' => [['name' => 'ftp_password', 'value' => $service->password]],
            ]]]]],
        ]);
        $this->result($packet->customer->set);
        $this->result($packet->webspace->set);

        return ProvisioningResult::ok("Changed the password of {$username}.");
    }

    /** Whether a subscription's details name the customer as its owner (Plesk may leave the owner out). */
    private function ownedBy(?SimpleXMLElement $info, int $customerId, string $login): bool
    {
        return match (true) {
            $info === null => true,
            isset($info->{'owner-login'}) => (string) $info->{'owner-login'} === $login,
            isset($info->{'owner-id'}) => (int) $info->{'owner-id'} === $customerId,
            default => true,
        };
    }

    private function customerId(Server $server, string $login): ?int
    {
        $customer = $this->find($this->packet($server, ['customer' => ['get' => [
            'filter' => ['login' => $login],
            'dataset' => ['gen_info' => []],
        ]]])->customer->get);

        return $customer === null ? null : (int) $customer->id;
    }

    private function addCustomer(Service $service, Server $server): int
    {
        $client = $service->client;
        $info = array_filter([
            'cname' => $client->company,
            'pname' => $client->fullName(),
            'login' => $service->username,
            'passwd' => $service->password,
            'email' => $client->email,
        ], filled(...));

        return (int) $this->result($this->packet($server, ['customer' => ['add' => ['gen_info' => $info]]])->customer->add)->id;
    }

    private function addSubscription(Service $service, Server $server, int $customerId, string $ip, string $plan): int
    {
        // Plesk reads the nodes in this order.
        $packet = $this->packet($server, ['webspace' => ['add' => [
            'gen_setup' => ['name' => $service->label, 'owner-id' => $customerId, 'htype' => 'vrt_hst', 'ip_address' => $ip, 'status' => self::STATUS_ACTIVE],
            'hosting' => ['vrt_hst' => [
                'property' => [['name' => 'ftp_login', 'value' => $service->username], ['name' => 'ftp_password', 'value' => $service->password]],
                'ip_address' => $ip,
            ]],
            'plan-name' => $plan,
        ]]]);

        return (int) $this->result($packet->webspace->add)->id;
    }

    private function setStatus(Service $service, Server $server, int $status): void
    {
        $this->result($this->packet($server, ['webspace' => ['set' => [
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

    /** The operation's result, or an error with Plesk's message. */
    private function result(?SimpleXMLElement $operation): SimpleXMLElement
    {
        return $this->find($operation) ?? throw new ModuleError('Plesk: the object was not found.');
    }

    /** The operation's result, or null when Plesk has no such object. */
    private function find(?SimpleXMLElement $operation): ?SimpleXMLElement
    {
        $result = $operation?->result ?? throw new ModuleError('Plesk answered without a result.');

        if ((string) $result->status === 'ok') {
            return $result;
        }

        if ((string) $result->errcode === self::NOT_FOUND) {
            return null;
        }

        throw new ModuleError('Plesk: '.(trim((string) $result->errtext) ?: 'the request failed.'));
    }

    /**
     * Sends one XML API packet. Operations are nested arrays: a key is an
     * element, a list repeats it, and an empty array is an empty element.
     *
     * @param  array<string, mixed>  $operations
     */
    private function packet(Server $server, array $operations): SimpleXMLElement
    {
        $request = $this->http($server)->withBody($this->xml($operations), 'text/xml');
        $request = filled($server->api_token)
            ? $request->withHeaders(['KEY' => $server->api_token])
            : $request->withHeaders(['HTTP_AUTH_LOGIN' => (string) $server->username, 'HTTP_AUTH_PASSWD' => (string) $server->password]);

        $response = $request->post('enterprise/control/agent.php');
        $operation = (string) array_key_first($operations);

        if (! $response->successful()) {
            throw $this->unexpected(self::PLESK, $response, $operation);
        }

        $packet = self::parse($response->body()) ?? throw $this->unexpected(self::PLESK, $response, $operation);

        if ((string) $packet->system->status === 'error') {
            throw new ModuleError('Plesk: '.trim((string) $packet->system->errtext));
        }

        return $packet;
    }

    private static function parse(string $xml): ?SimpleXMLElement
    {
        $errors = libxml_use_internal_errors(true);
        $packet = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        return $packet === false ? null : $packet;
    }

    /** @param  array<string, mixed>  $operations */
    private function xml(array $operations): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $this->append($document, $document->appendChild($document->createElement('packet')), $operations);

        return (string) $document->saveXML();
    }

    /** @param  array<string, mixed>  $children */
    private function append(DOMDocument $document, DOMNode $parent, array $children): void
    {
        foreach ($children as $name => $value) {
            foreach (is_array($value) && $value !== [] && array_is_list($value) ? $value : [$value] as $item) {
                $node = $parent->appendChild($document->createElement($name));

                if (is_array($item)) {
                    $this->append($document, $node, $item);
                } else {
                    $node->appendChild($document->createTextNode((string) $item));
                }
            }
        }
    }
}
