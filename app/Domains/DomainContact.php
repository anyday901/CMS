<?php

namespace App\Domains;

use App\Models\Client;

/** The registrant (owner) details a registrar keeps for a domain. */
final readonly class DomainContact
{
    public const FIELDS = ['first_name', 'last_name', 'company', 'email', 'phone', 'address1', 'address2', 'city', 'state', 'postcode', 'country'];

    /** Calling codes for the countries most clients come from; others need the number written with its code. */
    private const CALLING_CODES = [
        'US' => '1', 'CA' => '1', 'GB' => '44', 'IE' => '353', 'AU' => '61', 'NZ' => '64', 'DE' => '49', 'FR' => '33',
        'ES' => '34', 'IT' => '39', 'NL' => '31', 'BE' => '32', 'AT' => '43', 'CH' => '41', 'SE' => '46', 'NO' => '47',
        'DK' => '45', 'FI' => '358', 'PL' => '48', 'PT' => '351', 'IN' => '91', 'SG' => '65', 'ZA' => '27', 'MX' => '52',
        'BR' => '55', 'JP' => '81', 'HK' => '852', 'AE' => '971', 'IL' => '972', 'PH' => '63',
    ];

    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone = '',
        public string $address1 = '',
        public string $city = '',
        public string $postcode = '',
        public string $country = '',
        public string $state = '',
        public string $address2 = '',
        public string $company = '',
    ) {}

    public static function fromClient(Client $client): self
    {
        return self::fromArray($client->only(self::FIELDS));
    }

    /** @param  array<string, mixed>  $values */
    public static function fromArray(array $values): self
    {
        $value = fn (string $key) => trim((string) ($values[$key] ?? ''));

        return new self(
            firstName: $value('first_name'),
            lastName: $value('last_name'),
            email: $value('email'),
            phone: $value('phone'),
            address1: $value('address1'),
            city: $value('city'),
            postcode: $value('postcode'),
            country: strtoupper($value('country')),
            state: $value('state'),
            address2: $value('address2'),
            company: $value('company'),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
        ];
    }

    /**
     * The phone in the "+1.5555551234" form registrars ask for. A number
     * without a country code gets the one for the contact's country, when known.
     */
    public function registrarPhone(): string
    {
        $phone = trim($this->phone);

        if ($phone === '') {
            return '';
        }
        if (preg_match('/^\+(\d{1,3})\.(\d{4,14})$/', $phone)) {
            return $phone;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '+')) {
            $code = self::callingCodeFor($digits);

            return '+'.$code.'.'.substr($digits, strlen($code));
        }

        $code = self::CALLING_CODES[$this->country] ?? '1';
        $digits = ltrim($digits, '0');
        if (str_starts_with($digits, $code) && strlen($digits) > 10) {
            $digits = substr($digits, strlen($code));
        }

        return '+'.$code.'.'.$digits;
    }

    /** The country calling code at the start of a full international number. */
    private static function callingCodeFor(string $digits): string
    {
        foreach ([3, 2, 1] as $length) {
            if (in_array(substr($digits, 0, $length), self::CALLING_CODES, true)) {
                return substr($digits, 0, $length);
            }
        }

        return substr($digits, 0, 1);
    }

    /** Fields a registrar can't do without; returns the first one missing, as a label. */
    public function missing(): ?string
    {
        foreach (['firstName' => 'first name', 'lastName' => 'last name', 'email' => 'email', 'phone' => 'phone', 'address1' => 'address', 'city' => 'city', 'postcode' => 'postcode', 'country' => 'country'] as $property => $label) {
            if ($this->{$property} === '') {
                return $label;
            }
        }

        return null;
    }
}
