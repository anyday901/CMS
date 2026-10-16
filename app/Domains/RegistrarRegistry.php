<?php

namespace App\Domains;

use App\Models\Domain;
use Illuminate\Support\Collection;

/** The registrar modules in config/domains.php, and their saved credentials. */
class RegistrarRegistry
{
    /** @return Collection<string, Registrar> keyed by registrar key */
    public function all(): Collection
    {
        return collect(config('domains.registrars', []))
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (Registrar $registrar) => $registrar->key());
    }

    public function find(?string $key): ?Registrar
    {
        return $key === null ? null : $this->all()->get($key);
    }

    /** @return array<string, array<string, string>> every registrar's credentials, keyed by registrar */
    public function allCredentials(): array
    {
        $saved = config('domains.credentials');

        return is_array($saved) ? $saved : (array) json_decode((string) $saved, true);
    }

    /** @return array<string, string> */
    public function credentials(string $key): array
    {
        return (array) ($this->allCredentials()[$key] ?? []);
    }

    /** Whether the registrar has its credentials filled in (checkboxes aside). */
    public function configured(?string $key): bool
    {
        $registrar = $this->find($key);

        if ($registrar === null) {
            return false;
        }

        $credentials = $this->credentials($key);

        return collect($registrar->configFields())
            ->reject(fn (array $field) => ($field['type'] ?? 'text') === 'checkbox' || ($field['optional'] ?? false))
            ->every(fn (array $field, string $name) => filled($credentials[$name] ?? null));
    }

    /**
     * The domain's registrar and its credentials.
     *
     * @return array{0: Registrar, 1: array<string, string>}
     *
     * @throws RegistrarError when it has none, or the registrar isn't set up.
     */
    public function forDomain(Domain $domain): array
    {
        $registrar = $this->find($domain->registrar)
            ?? throw new RegistrarError($domain->registrar ? "The {$domain->registrar} registrar module isn't installed." : "{$domain->name} has no registrar.");

        if (! $this->configured($registrar->key())) {
            throw new RegistrarError("Enter the {$registrar->label()} credentials under Settings > Domains first.");
        }

        return [$registrar, $this->credentials($registrar->key())];
    }
}
