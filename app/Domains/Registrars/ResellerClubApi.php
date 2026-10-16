<?php

namespace App\Domains\Registrars;

use App\Domains\DomainContact;
use App\Domains\RegistrarError;
use App\Domains\RegistrarResult;
use App\Models\Domain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Calls to ResellerClub's HTTP API, and the customer and contact each domain is registered with. */
final class ResellerClubApi
{
    private const LIVE = 'https://httpapi.com/api/';

    private const TEST = 'https://test.httpapi.com/api/';

    /**
     * @param  array<string, string|int|list<string>>  $params  A list repeats the name, as the API wants for ns and tlds.
     */
    public static function get(string $path, array $params, array $credentials, bool $plain = false): mixed
    {
        return self::send('GET', $path, $params, $credentials, $plain);
    }

    public static function post(string $path, array $params, array $credentials, bool $plain = false): mixed
    {
        return self::send('POST', $path, $params, $credentials, $plain);
    }

    /** @param  bool  $plain  The call answers with a bare value, such as a new id. */
    private static function send(string $method, string $path, array $params, array $credentials, bool $plain): mixed
    {
        $query = self::query(['auth-userid' => $credentials['reseller_id'] ?? '', 'api-key' => $credentials['api_key'] ?? ''] + $params);
        $url = (! empty($credentials['test_mode']) ? self::TEST : self::LIVE).$path;

        try {
            $request = Http::timeout(30)->acceptJson();
            $response = $method === 'GET'
                ? $request->withOptions(['query' => $query])->get($url)
                : $request->withBody($query, 'application/x-www-form-urlencoded')->post($url);
        } catch (ConnectionException $e) {
            throw new RegistrarError("ResellerClub couldn't be reached: {$e->getMessage()}");
        }

        $json = $response->json();

        if (is_array($json) && in_array(strtolower((string) ($json['status'] ?? '')), ['error', 'failed'], true) && ! isset($json['actionstatus'])) {
            throw new RegistrarError('ResellerClub refused '.$path.': '.($json['message'] ?? $json['error'] ?? 'no reason given'));
        }
        if (! $response->successful()) {
            throw new RegistrarError("ResellerClub answered HTTP {$response->status()} to {$path}.");
        }

        return $plain ? trim($response->body(), "\" \n") : (array) $json;
    }

    /** a=1&ns=x&ns=y: the API wants repeated names, not PHP's ns[0]=x. */
    private static function query(array $params): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            foreach ((array) $value as $one) {
                $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $one);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * The client's customer in the reseller account (found by email, else
     * made) and a contact for this domain.
     *
     * @return array{0: string, 1: string}
     */
    public static function customerAndContact(Domain $domain, array $credentials): array
    {
        $contact = $domain->registrant();
        $customer = $domain->registrarValue('customer_id');

        if (blank($customer)) {
            try {
                $customer = (string) (self::get('customers/details.json', ['username' => [$contact->email]], $credentials)['customerid'] ?? '');
            } catch (RegistrarError) {
                $customer = '';
            }
        }
        if ($customer === '') {
            $customer = (string) self::post('customers/v2/signup.json', [
                'username' => $contact->email,
                'passwd' => Str::password(16, symbols: false).'a1',
                'name' => trim("{$contact->firstName} {$contact->lastName}"),
                'company' => $contact->company ?: 'N/A',
                'address-line-1' => $contact->address1,
                'city' => $contact->city,
                'state' => $contact->state ?: 'Not Applicable',
                'country' => $contact->country,
                'zipcode' => $contact->postcode,
                'lang-pref' => 'en',
            ] + self::phone($contact), $credentials, true);
        }

        $contactId = $domain->registrarValue('contact_id') ?: (string) self::post('contacts/add.json', [
            'customer-id' => $customer,
            'type' => 'Contact',
        ] + self::contactFields($contact), $credentials, true);
        $domain->rememberRegistrarValues(['customer_id' => $customer, 'contact_id' => $contactId]);

        return [$customer, $contactId];
    }

    public static function contactIds(string $customer, string $contact): array
    {
        return [
            'customer-id' => $customer,
            'reg-contact-id' => $contact,
            'admin-contact-id' => $contact,
            'tech-contact-id' => $contact,
            'billing-contact-id' => $contact,
        ];
    }

    public static function contactFields(DomainContact $contact): array
    {
        return [
            'name' => trim("{$contact->firstName} {$contact->lastName}"),
            'company' => $contact->company ?: 'N/A',
            'email' => $contact->email,
            'address-line-1' => $contact->address1,
            'address-line-2' => $contact->address2,
            'city' => $contact->city,
            'state' => $contact->state,
            'country' => $contact->country,
            'zipcode' => $contact->postcode,
        ] + self::phone($contact);
    }

    /** @return array{phone-cc: string, phone: string} */
    private static function phone(DomainContact $contact): array
    {
        [$code, $number] = array_pad(explode('.', ltrim($contact->registrarPhone(), '+'), 2), 2, '');

        return ['phone-cc' => $code, 'phone' => $number];
    }

    /** @param  array<string, mixed>  $reply */
    public static function orderResult(array $reply, bool $transfer, array $data = []): RegistrarResult
    {
        $status = strtolower((string) ($reply['actionstatus'] ?? $reply['status'] ?? ''));

        if (in_array($status, ['failed', 'error'], true)) {
            return RegistrarResult::failed('ResellerClub refused it: '.($reply['actionstatusdesc'] ?? $reply['message'] ?? 'no reason given'));
        }

        $data += ['order_id' => (string) ($reply['entityid'] ?? '')];

        return $transfer ? RegistrarResult::inProgress($data) : RegistrarResult::done(data: $data);
    }
}
