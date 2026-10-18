<?php

namespace App\Billing;

use App\Models\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * EU VAT numbers: telling them apart from other tax IDs, checking them with
 * the European Commission's VIES service, and deciding when an invoice is
 * reverse charged. Any other tax ID is kept as typed and never checked.
 */
class VatNumbers
{
    public const VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /** VIES's country prefixes: the EU's member states (Greece is EL) and Northern Ireland (XI). */
    public const PREFIXES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

    /** Check EU VAT numbers with VIES when clients save them. Reverse charge needs checked numbers, so it turns checking on too. */
    public static function checking(): bool
    {
        return (bool) config('billing.vat_validation') || (bool) config('billing.reverse_charge');
    }

    /** An EU VAT number in VIES's form ("DE123456789"), or any other tax ID trimmed as typed. */
    public static function normalize(?string $taxId): ?string
    {
        $taxId = trim((string) $taxId);
        $compact = strtoupper((string) preg_replace('/[\s.\-]/', '', $taxId));

        return match (true) {
            $taxId === '' => null,
            self::prefix($compact) !== null => $compact,
            default => $taxId,
        };
    }

    /** The VIES country prefix of an EU VAT number, or null for any other tax ID. */
    public static function prefix(?string $taxId): ?string
    {
        $compact = strtoupper((string) preg_replace('/[\s.\-]/', '', (string) $taxId));

        return in_array(substr($compact, 0, 2), self::PREFIXES, true) && preg_match('/^[A-Z]{2}[0-9A-Z+*]{2,12}$/', $compact)
            ? substr($compact, 0, 2)
            : null;
    }

    /**
     * Asks VIES whether an EU VAT number exists: true or false, or null when
     * VIES or the member state's database can't answer right now.
     */
    public function check(string $vatNumber): ?bool
    {
        $vatNumber = (string) self::normalize($vatNumber);

        if (self::prefix($vatNumber) === null) {
            return false;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->post(self::VIES_URL, [
                'countryCode' => substr($vatNumber, 0, 2),
                'vatNumber' => substr($vatNumber, 2),
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if (is_bool($response->json('valid'))) {
            return $response->json('valid');
        }

        // Anything else, such as MS_UNAVAILABLE or TIMEOUT, means "ask again later".
        return collect((array) $response->json('errorWrappers'))->contains(fn ($error) => ($error['error'] ?? null) === 'INVALID_INPUT') ? false : null;
    }

    /**
     * The tax ID columns to save for a client. An EU VAT number is checked
     * with VIES (unless it's the one already checked) and refused when VIES
     * says it doesn't exist. VIES being down never stops the save: the number
     * is kept and left unverified until it's saved again.
     *
     * @return array{tax_id: ?string, tax_id_verified_at: mixed}
     *
     * @throws ValidationException when it isn't a valid EU VAT number
     */
    public function attributes(?string $taxId, ?Client $client = null, string $field = 'tax_id'): array
    {
        $taxId = self::normalize($taxId);

        if ($taxId === null || ! self::checking() || self::prefix($taxId) === null) {
            return ['tax_id' => $taxId, 'tax_id_verified_at' => null];
        }

        if ($client?->tax_id === $taxId && $client->tax_id_verified_at !== null) {
            return ['tax_id' => $taxId, 'tax_id_verified_at' => $client->tax_id_verified_at];
        }

        $valid = $this->check($taxId);

        if ($valid === false) {
            throw ValidationException::withMessages([$field => __('The EU VAT database (VIES) says this VAT number isn\'t valid.')]);
        }

        return ['tax_id' => $taxId, 'tax_id_verified_at' => $valid ? now() : null];
    }

    /**
     * Whether the client's invoices are reverse charged: the setting is on,
     * the client has an EU VAT number VIES confirmed, and it's from another
     * EU country than the company's own VAT number.
     */
    public static function reverseCharged(Client $client): bool
    {
        $ours = self::prefix(config('billing.company.tax_id'));
        $theirs = self::prefix($client->tax_id);

        return config('billing.reverse_charge') && ! $client->tax_exempt && $client->tax_id_verified_at !== null
            && $ours !== null && $theirs !== null && $ours !== $theirs;
    }
}
