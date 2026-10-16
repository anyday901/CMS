<?php

namespace App\Domains;

use App\Models\Tld;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Checks names for the store's domain search: at the TLD's registrar when
 * it has one set up (one request per registrar), else in the public WHOIS
 * records. A name typed with no extension is checked under each TLD sold.
 */
class DomainLookup
{
    /** TLDs checked for a name typed without one, and suggestions shown alongside a full name. */
    public const SUGGESTIONS = 8;

    public function __construct(private RegistrarRegistry $registrars, private Whois $whois) {}

    /**
     * The tidied search, and each name checked with the searched one first.
     *
     * @return array{name: ?string, results: list<array{name: string, tld: Tld, available: ?bool}>, error: ?string}
     */
    public function search(string $input): array
    {
        $tlds = Tld::sold()->with('prices')->get();

        if ($tlds->isEmpty()) {
            return ['name' => null, 'results' => [], 'error' => 'No domains are for sale yet.'];
        }

        $parsed = DomainName::parse($input, $tlds);
        $bare = $parsed === null ? DomainName::bareName($input) : null;

        if ($parsed === null && $bare === null) {
            $normalised = DomainName::normalise($input);

            return ['name' => $normalised, 'results' => [], 'error' => $normalised === null
                ? "That isn't a domain name. Use letters, numbers and hyphens, such as example.com."
                : "We don't sell that extension. Try one of: ".$tlds->take(self::SUGGESTIONS)->map->label()->join(', ').'.'];
        }

        $sld = $parsed?->sld ?? $bare;
        $wanted = $parsed ? collect([$parsed->tld])->merge($tlds->reject(fn (Tld $tld) => $tld->is($parsed->tld))->take(self::SUGGESTIONS - 1)) : $tlds->take(self::SUGGESTIONS);
        $names = $wanted->mapWithKeys(fn (Tld $tld) => ["{$sld}.{$tld->extension}" => $tld]);

        return ['name' => $parsed?->full() ?? $sld, 'results' => $this->check($names), 'error' => null];
    }

    /**
     * Whether one name can be registered: true, false, or null when nobody could say.
     */
    public function isAvailable(string $name, Tld $tld): ?bool
    {
        return $this->check(collect([$name => $tld]))[0]['available'];
    }

    /**
     * @param  Collection<string, Tld>  $names
     * @return list<array{name: string, tld: Tld, available: ?bool}>
     */
    private function check(Collection $names): array
    {
        $answers = [];

        foreach ($names->groupBy(fn (Tld $tld) => $this->registrars->configured($tld->registrar) ? $tld->registrar : '', true) as $key => $group) {
            $answers += $key === '' ? $this->viaWhois($group) : $this->viaRegistrar((string) $key, $group);
        }

        return $names->map(fn (Tld $tld, string $name) => ['name' => $name, 'tld' => $tld, 'available' => $answers[$name] ?? null])->values()->all();
    }

    /**
     * @param  Collection<string, Tld>  $names
     * @return array<string, ?bool>
     */
    private function viaRegistrar(string $key, Collection $names): array
    {
        try {
            return $this->registrars->find($key)->available($names->keys()->all(), $this->registrars->credentials($key));
        } catch (RegistrarError $e) {
            Log::warning('Domain availability check failed', ['registrar' => $key, 'error' => $e->getMessage()]);

            return $this->viaWhois($names);
        }
    }

    /**
     * @param  Collection<string, Tld>  $names
     * @return array<string, ?bool>
     */
    private function viaWhois(Collection $names): array
    {
        return $names->map(fn (Tld $tld, string $name) => $this->whois->lookup($name, $tld)->available)->all();
    }
}
