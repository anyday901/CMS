<?php

namespace App\Domains\Registrars;

use App\Domains\DomainContact;
use App\Domains\RegistrarError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/** Calls to Enom's reseller API (interface.asp), and the forms it wants values in. */
final class EnomApi
{
    use XmlReplies;

    private const LIVE = 'https://reseller.enom.com/interface.asp';

    private const TEST = 'https://resellertest.enom.com/interface.asp';

    private const NAME = 'Enom';

    /** @param  array<string, string|int>  $params */
    public static function call(string $command, array $params, array $credentials): SimpleXMLElement
    {
        try {
            $response = Http::timeout(30)->asForm()->post(! empty($credentials['test_mode']) ? self::TEST : self::LIVE, [
                'uid' => $credentials['username'] ?? '',
                'pw' => $credentials['api_token'] ?? '',
                'command' => $command,
                'responsetype' => 'xml',
            ] + $params);
        } catch (ConnectionException $e) {
            throw new RegistrarError("Enom couldn't be reached: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RegistrarError("Enom answered HTTP {$response->status()} to {$command}.");
        }

        $xml = self::xml($response->body(), self::NAME);

        if ((int) self::text($xml, '/interface-response/ErrCount') > 0) {
            $errors = array_map(fn ($error) => trim((string) $error), $xml->xpath('/interface-response/errors/*') ?: []);

            throw new RegistrarError('Enom refused '.$command.': '.(implode(' ', array_filter($errors)) ?: 'no reason given'));
        }

        return $xml;
    }

    public static function date(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  list<string>  $types */
    public static function contacts(DomainContact $contact, array $types): array
    {
        $params = [];
        foreach ($types as $type) {
            $params += [
                "{$type}FirstName" => $contact->firstName,
                "{$type}LastName" => $contact->lastName,
                "{$type}OrganizationName" => $contact->company,
                "{$type}Address1" => $contact->address1,
                "{$type}Address2" => $contact->address2,
                "{$type}City" => $contact->city,
                "{$type}StateProvince" => $contact->state,
                "{$type}PostalCode" => $contact->postcode,
                "{$type}Country" => $contact->country,
                "{$type}EmailAddress" => $contact->email,
                "{$type}Phone" => $contact->registrarPhone(),
            ];
        }

        return $params;
    }

    /** @param  list<string>  $nameservers */
    public static function nameservers(array $nameservers): array
    {
        $params = [];
        foreach (array_values($nameservers) as $i => $nameserver) {
            $params['NS'.($i + 1)] = $nameserver;
        }

        return $params;
    }
}
