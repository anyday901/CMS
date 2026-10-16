<?php

namespace App\Provisioning\Whmcs;

use App\Models\ProductOption;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceOption;

/** The $params array WHMCS passes to a server module's functions, built from this app's records. */
final class Params
{
    /**
     * @param  array<string, string>  $settings  the product's module settings as $params entries (see configOptions())
     * @return array<string, mixed>
     */
    public static function forService(string $moduleType, Service $service, ?Server $server, int $defaultPort, array $settings): array
    {
        $client = $service->client;

        return [
            'accountid' => $service->id,
            'serviceid' => $service->id,
            'userid' => $service->client_id,
            'domain' => (string) $service->label,
            'username' => (string) $service->username,
            'password' => (string) $service->password,
            'packageid' => $service->product_id,
            'pid' => $service->product_id,
            'status' => ucfirst($service->status->value),
            'type' => 'hostingaccount',
            'producttype' => 'hostingaccount',
            'moduletype' => $moduleType,
            'dedicatedip' => (string) ($service->provisioning_data['ip'] ?? ''),
            'assignedips' => '',
            ...$settings,
            'customfields' => $service->customFieldsByName(),
            'configoptions' => $service->options->loadMissing(['option', 'choice'])
                ->mapWithKeys(fn (ServiceOption $chosen) => [$chosen->option->name => match ($chosen->option->type) {
                    ProductOption::DROPDOWN => $chosen->choice?->name,
                    ProductOption::QUANTITY => $chosen->quantity,
                    default => 1,
                }])->all(),
            'clientsdetails' => [
                'userid' => $client->id,
                'id' => $client->id,
                'firstname' => (string) $client->first_name,
                'lastname' => (string) $client->last_name,
                'fullname' => trim("{$client->first_name} {$client->last_name}"),
                'companyname' => (string) $client->company,
                'email' => (string) $client->email,
                'address1' => (string) $client->address1,
                'address2' => (string) $client->address2,
                'city' => (string) $client->city,
                'state' => (string) $client->state,
                'fullstate' => (string) $client->state,
                'postcode' => (string) $client->postcode,
                'countrycode' => (string) $client->country,
                'country' => (string) $client->country,
                'phonenumber' => (string) $client->phone,
                'language' => (string) $client->language,
            ],
            ...($server ? self::forServer($server, $defaultPort) : ['server' => false, 'serverid' => 0]),
        ];
    }

    /** @return array<string, mixed> */
    public static function forServer(Server $server, int $defaultPort): array
    {
        return [
            'server' => true,
            'serverid' => $server->id,
            'serverip' => (string) $server->ip_address,
            'serverhostname' => (string) $server->hostname,
            'serverusername' => (string) $server->username,
            'serverpassword' => (string) $server->password,
            'serveraccesshash' => (string) $server->api_token,
            'serversecure' => (bool) $server->secure,
            'serverhttpprefix' => $server->secure ? 'https' : 'http',
            'serverport' => $server->port ?: $defaultPort,
        ];
    }

    /**
     * configoption1 to configoption24 from a product's module settings, ''
     * where it sets nothing. $names maps a position to the setting's name
     * for modules converted from WHMCS, which save settings by name.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    public static function configOptions(array $config, array $names = []): array
    {
        $options = [];

        for ($i = 1; $i <= ConfigFields::MAX; $i++) {
            $options["configoption{$i}"] = (string) ($config[$names[$i] ?? "configoption{$i}"] ?? '');
        }

        return $options;
    }
}
