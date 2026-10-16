<?php

namespace App\Marketplace;

use App\Addons\AddonManager;
use App\System\Version;
use App\Themes\ThemeManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Settings > Marketplace: the registry of add-ons and themes, and installing from it. */
class Marketplace
{
    private const CACHE_KEY = 'marketplace.registry';

    /** The registry, downloads and homepages must all use it. */
    private const HTTPS = 'https://';

    public function __construct(private PackageInstaller $installer, private ThemeManager $themes, private AddonManager $addons) {}

    public static function installsAllowed(): bool
    {
        return (bool) config('marketplace.installs');
    }

    /**
     * The registry's packages, checked at most hourly. Null when it can't be read.
     *
     * @return Collection<int, array{type: string, key: string, name: string, description: string, author: string, homepage: string|null, version: string, requires: string|null, download_url: string, sha256: string}>|null
     */
    public function packages(bool $fresh = false): ?Collection
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $packages = Cache::get(self::CACHE_KEY);

        if ($packages === null) {
            $packages = $this->fetch() ?? false;
            Cache::put(self::CACHE_KEY, $packages, now()->addMinutes($packages === false ? 10 : 60));
        }

        return $packages === false ? null : collect($packages);
    }

    public function find(string $type, string $key): ?array
    {
        return $this->packages()?->first(fn (array $package) => $package['type'] === $type && $package['key'] === $key);
    }

    /**
     * What's installed, by "type:key", with its version.
     *
     * @return array<string, string>
     */
    public function installedVersions(): array
    {
        $versions = [];

        foreach ($this->themes->all() as $theme) {
            $versions['theme:'.$theme->key] = $theme->version;
        }

        foreach (glob(rtrim((string) config('marketplace.addons_path'), '/').'/*/addon.json') ?: [] as $file) {
            if ($manifest = Manifest::read(dirname($file), Manifest::ADDON)) {
                $versions['addon:'.$manifest['key']] = $manifest['version'];
            }
        }

        foreach ($this->addons->available() as $key => $addon) {
            $versions['addon:'.$key] ??= $addon->version();
        }

        return $versions;
    }

    /** Whether an installed package came from the marketplace (or an upload), so it can be removed here. */
    public function removable(string $type, string $key): bool
    {
        try {
            return is_dir($this->installer->target($type, $key)) && Manifest::read($this->installer->target($type, $key), $type) !== null;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Downloads a package from the registry, checks its checksum and installs it. */
    public function install(string $type, string $key): array
    {
        $package = $this->find($type, $key) ?? throw new PackageException('That package isn\'t in the marketplace.');
        $zip = storage_path('app/marketplace/download-'.Str::random(12).'.zip');

        try {
            @mkdir(dirname($zip), 0700, true);
            Http::timeout(120)->sink($zip)->get($package['download_url'])->throw();

            if (! hash_equals($package['sha256'], hash_file('sha256', $zip))) {
                throw new PackageException('The download doesn\'t match the checksum in the marketplace, so it wasn\'t installed.');
            }

            return $this->installer->install($zip, ['type' => $type, 'key' => $key], 'the marketplace');
        } finally {
            @unlink($zip);
        }
    }

    private function fetch(): ?array
    {
        $packages = $this->registry();

        if ($packages === null) {
            return null;
        }

        return collect($packages)
            ->filter(fn ($package) => is_array($package)
                && in_array($package['type'] ?? null, [Manifest::ADDON, Manifest::THEME], true)
                && is_string($package['key'] ?? null) && preg_match(Manifest::KEY, $package['key'])
                && is_string($package['download_url'] ?? null) && str_starts_with($package['download_url'], self::HTTPS)
                && is_string($package['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $package['sha256']))
            ->map(fn (array $package) => [
                'type' => $package['type'],
                'key' => $package['key'],
                'name' => Str::limit((string) ($package['name'] ?? $package['key']), 80),
                'description' => Str::limit((string) ($package['description'] ?? ''), 500),
                'author' => Str::limit((string) ($package['author'] ?? ''), 80),
                'homepage' => is_string($package['homepage'] ?? null) && str_starts_with($package['homepage'], self::HTTPS) ? $package['homepage'] : null,
                'version' => (string) ($package['version'] ?? ''),
                'requires' => isset($package['requires']) ? (string) $package['requires'] : null,
                'compatible' => Version::satisfies($package['requires'] ?? null),
                'download_url' => $package['download_url'],
                'sha256' => $package['sha256'],
            ])
            ->sortBy(fn (array $package) => [$package['type'], strtolower($package['name'])])
            ->values()
            ->all();
    }

    /** The registry's raw packages list, or null when it can't be read. */
    private function registry(): ?array
    {
        $url = (string) config('marketplace.registry_url');

        if (! str_starts_with($url, self::HTTPS)) {
            return null;
        }

        try {
            $packages = Http::acceptJson()->timeout(10)->get($url)->throw()->json('packages');
        } catch (Throwable) {
            return null;
        }

        return is_array($packages) ? $packages : null;
    }
}
