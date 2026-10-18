<?php

namespace App\Provisioning;

use App\Models\Server;
use App\Models\Service;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A service's account on a server: the domain it is set up for, and the
 * username and password it logs in with.
 */
final class ServerAccount
{
    /** Characters for new passwords; each kind is used at least once. */
    private const PASSWORD_SETS = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@*-_.'];

    private const PASSWORD_LENGTH = 16;

    private const USERNAME_LENGTH = 8;

    /** Whether $value looks like a domain name with at least one dot. */
    public static function isDomain(string $value): bool
    {
        return str_contains($value, '.') && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** A validation rule for a service's domain name. */
    public static function domainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! self::isDomain((string) $value)) {
                $fail('Enter the domain name, such as example.com.')->translate();
            }
        };
    }

    /** A random password with lower and upper case letters, digits and a symbol. */
    public static function newPassword(): string
    {
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];
        $chars = array_map($pick, self::PASSWORD_SETS);
        $all = implode('', self::PASSWORD_SETS);

        while (count($chars) < self::PASSWORD_LENGTH) {
            $chars[] = $pick($all);
        }

        return implode('', Arr::shuffle($chars));
    }

    /**
     * Gives a new service a username and password unless staff already set
     * them: the username from $newUsername, and a random password.
     *
     * @param  Closure(): string  $newUsername
     */
    public static function fillLogin(Service $service, Closure $newUsername): void
    {
        $service->forceFill([
            'username' => filled($service->username) ? $service->username : $newUsername(),
            'password' => filled($service->password) ? $service->password : self::newPassword(),
        ]);

        if ($service->isDirty()) {
            $service->save();
        }
    }

    /** The account's username on the server. */
    public static function username(Service $service): string
    {
        return filled($service->username)
            ? $service->username
            : throw new ModuleError('The service has no username on the server. Run create first, or enter the username on the service page.');
    }

    /**
     * Up to 8 lower case letters and digits from the domain (or the client's
     * name), starting with a letter, and not used by another service on the
     * same server. Control panels refuse names starting with "test".
     */
    public static function newUsername(Service $service, Server $server): string
    {
        $source = Str::before((string) $service->label, '.') ?: $service->client->first_name.$service->client->last_name;
        $base = ltrim(preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($source))), '0123456789');
        $base = Str::startsWith($base, 'test') ? 'u'.$base : $base;
        $base = substr(str_pad($base, 3, 'x'), 0, self::USERNAME_LENGTH);

        $taken = $server->services()->whereKeyNot($service->id)->whereNotNull('username')->pluck('username');
        $username = $base;

        for ($tries = 0; $taken->contains($username) && $tries < 50; $tries++) {
            $username = substr($base, 0, self::USERNAME_LENGTH - 3).random_int(100, 999);
        }

        return $username;
    }
}
