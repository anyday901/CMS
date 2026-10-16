<?php

namespace App\Domains;

use App\Models\Tld;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Looks domains up in the public registry records: RDAP, the JSON service
 * most registries run (found through IANA's list), else the TLD's port 43
 * WHOIS server when one is set on it.
 */
class Whois
{
    public const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    private const TIMEOUT = 10;

    public function __construct(private WhoisSocket $socket) {}

    public function lookup(string $name, ?Tld $tld = null): WhoisResult
    {
        $base = $this->rdapServer($name);

        if ($base !== null) {
            try {
                return $this->rdap($name, $base);
            } catch (ConnectionException|RegistrarError $e) {
                if (blank($tld?->whois_server)) {
                    return new WhoisResult($name, null, error: $e->getMessage());
                }
            }
        }

        if (filled($tld?->whois_server)) {
            return $this->port43($name, $tld);
        }

        return new WhoisResult($name, null, error: "No WHOIS service is known for {$name}.");
    }

    /** The RDAP base URL for the name's extension, from IANA's list (kept for a day). */
    private function rdapServer(string $name): ?string
    {
        $services = Cache::remember('rdap-bootstrap', now()->addDay(), function () {
            try {
                $response = Http::timeout(self::TIMEOUT)->get(self::BOOTSTRAP_URL);
            } catch (ConnectionException) {
                return [];
            }

            return $response->successful() ? (array) $response->json('services') : [];
        });

        $labels = explode('.', $name);
        // The longest matching extension wins: "co.uk" before "uk".
        for ($i = 1; $i < count($labels); $i++) {
            $extension = implode('.', array_slice($labels, $i));
            foreach ($services as [$extensions, $urls]) {
                if (in_array($extension, (array) $extensions, true)) {
                    return rtrim((string) Arr::first((array) $urls, fn ($url) => str_starts_with((string) $url, 'https://'), Arr::first((array) $urls)), '/').'/';
                }
            }
        }

        return null;
    }

    private function rdap(string $name, string $base): WhoisResult
    {
        $response = Http::timeout(self::TIMEOUT)->accept('application/rdap+json')->get($base.'domain/'.rawurlencode($name));

        if ($response->status() === 404) {
            return new WhoisResult($name, true);
        }
        if (! $response->successful()) {
            throw new RegistrarError("The registry's RDAP service answered HTTP {$response->status()}.");
        }

        return new WhoisResult($name, false, $this->rdapFields((array) $response->json()));
    }

    /** @return array<string, string> */
    private function rdapFields(array $record): array
    {
        $events = collect($record['events'] ?? [])->mapWithKeys(fn ($event) => [($event['eventAction'] ?? '') => substr((string) ($event['eventDate'] ?? ''), 0, 10)]);
        $registrar = collect($record['entities'] ?? [])->first(fn ($entity) => in_array('registrar', (array) ($entity['roles'] ?? []), true));
        $registrarName = collect($registrar['vcardArray'][1] ?? [])->first(fn ($entry) => ($entry[0] ?? null) === 'fn')[3] ?? null;

        return array_filter([
            'Domain' => strtolower((string) ($record['ldhName'] ?? '')),
            'Registrar' => (string) $registrarName,
            'Registered' => (string) $events->get('registration'),
            'Expires' => (string) $events->get('expiration'),
            'Updated' => (string) $events->get('last changed'),
            'Status' => implode(', ', (array) ($record['status'] ?? [])),
            'Nameservers' => collect($record['nameservers'] ?? [])->map(fn ($ns) => strtolower((string) ($ns['ldhName'] ?? '')))->filter()->join(', '),
            'DNSSEC' => isset($record['secureDNS']['delegationSigned']) ? ($record['secureDNS']['delegationSigned'] ? 'Signed' : 'Unsigned') : '',
        ], fn ($value) => $value !== '');
    }

    private function port43(string $name, Tld $tld): WhoisResult
    {
        try {
            $text = $this->socket->query((string) $tld->whois_server, $name);
        } catch (RegistrarError $e) {
            return new WhoisResult($name, null, error: $e->getMessage());
        }

        $free = filled($tld->whois_available) && str_contains(mb_strtolower($text), mb_strtolower((string) $tld->whois_available));

        return new WhoisResult($name, filled($tld->whois_available) ? $free : null, text: trim($text));
    }
}
