<?php

namespace App\Domains;

use App\Models\Tld;
use Illuminate\Support\Collection;

/**
 * Domain names as clients type them: "Example.COM", "www.example.com",
 * "https://bücher.de/" are tidied to "example.com", "example.com" and
 * "xn--bcher-kva.de", and split into the name and a TLD that is sold.
 */
final readonly class DomainName
{
    private function __construct(public string $sld, public Tld $tld) {}

    /** "example.com" */
    public function full(): string
    {
        return "{$this->sld}.{$this->tld->extension}";
    }

    /** The tidied name, or null when it can't be a domain name. */
    public static function normalise(string $input): ?string
    {
        $name = mb_strtolower(trim($input));
        $name = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $name);
        $name = preg_replace('#[/?\#].*$#', '', $name);
        $name = rtrim(preg_replace('/^www\./', '', $name), '.');

        if ($name === '' || preg_match('/\s/', $name)) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $name)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                return null;
            }
            $name = $ascii;
        }

        return self::valid($name) ? $name : null;
    }

    /** Whether every label is 1 to 63 letters, digits and inner hyphens, at most 253 characters. */
    public static function valid(string $name): bool
    {
        return strlen($name) <= 253
            && (bool) preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $name);
    }

    /**
     * Splits a name into the part bought and a TLD that's sold, matching the
     * longest extension: "example.co.uk" is "example" under "co.uk" when that
     * is sold. "sub.example.com" is refused: only the registrable part can be ordered.
     *
     * @param  Collection<int, Tld>|null  $tlds  Defaults to the active TLDs.
     */
    public static function parse(string $input, ?Collection $tlds = null): ?self
    {
        $name = self::normalise($input);

        if ($name === null) {
            return null;
        }

        $tlds ??= Tld::sold()->get();
        $tld = $tlds->filter(fn (Tld $tld) => str_ends_with($name, '.'.$tld->extension))
            ->sortByDesc(fn (Tld $tld) => strlen($tld->extension))
            ->first();

        if ($tld === null) {
            return null;
        }

        $sld = substr($name, 0, -strlen($tld->extension) - 1);

        return str_contains($sld, '.') ? null : new self($sld, $tld);
    }

    /** A name typed without an extension, such as "example", or null when it has one. */
    public static function bareName(string $input): ?string
    {
        $name = self::normalise($input.'.test');

        return $name !== null && substr_count($name, '.') === 1 ? substr($name, 0, -5) : null;
    }
}
