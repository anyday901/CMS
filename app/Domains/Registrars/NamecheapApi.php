<?php

namespace App\Domains\Registrars;

use App\Domains\DomainContact;
use App\Domains\RegistrarError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/** Calls to Namecheap's XML API, and the forms it wants values in. */
final class NamecheapApi
{
    use XmlReplies;

    private const LIVE = 'https://api.namecheap.com/xml.response';

    private const SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';

    private const NAME = 'Namecheap';

    /** @param  array<string, string|int>  $params */
    public static function call(string $command, array $params, array $credentials): SimpleXMLElement
    {
        try {
            $response = Http::timeout(30)->asForm()->post(! empty($credentials['sandbox']) ? self::SANDBOX : self::LIVE, [
                'ApiUser' => $credentials['api_user'] ?? '',
                'ApiKey' => $credentials['api_key'] ?? '',
                'UserName' => $credentials['api_user'] ?? '',
                'ClientIp' => $credentials['client_ip'] ?? '',
                'Command' => $command,
            ] + $params);
        } catch (ConnectionException $e) {
            throw new RegistrarError("Namecheap couldn't be reached: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RegistrarError("Namecheap answered HTTP {$response->status()} to {$command}.");
        }

        $xml = self::xml($response->body(), self::NAME);

        if (strtoupper((string) $xml['Status']) !== 'OK') {
            $error = $xml->xpath('/ApiResponse/Errors/Error')[0] ?? null;

            throw new RegistrarError('Namecheap refused '.$command.': '.(trim((string) $error) ?: 'no reason given'), (int) ($error['Number'] ?? 0));
        }

        return $xml;
    }

    /** Namecheap writes dates as "MM/DD/YYYY". */
    public static function date(?string $value): ?CarbonImmutable
    {
        if (blank($value) || ! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $value, $m)) {
            return null;
        }

        return CarbonImmutable::create((int) $m[3], (int) $m[1], (int) $m[2]);
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
                "{$type}StateProvince" => $contact->state ?: $contact->city,
                "{$type}PostalCode" => $contact->postcode,
                "{$type}Country" => $contact->country,
                "{$type}Phone" => $contact->registrarPhone(),
                "{$type}EmailAddress" => $contact->email,
            ];
        }

        return array_filter($params, fn ($value) => $value !== '');
    }
}
