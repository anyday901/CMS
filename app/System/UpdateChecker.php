<?php

namespace App\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Finds the newest release on GitHub, checked at most every few hours. */
class UpdateChecker
{
    private const CACHE_KEY = 'updates.latest';

    private const CACHE_HOURS = 6;

    /**
     * @return array{version: string, name: string, notes: string, url: string, published_at: string|null, download_url: string|null, checksum_url: string|null}|null
     *                                                                                                                                                                null when it can't be checked
     */
    public function latest(bool $fresh = false): ?array
    {
        if (! config('updates.enabled')) {
            return null;
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $release = Cache::get(self::CACHE_KEY);

        if ($release === null) {
            $release = $this->fetch() ?? false;
            // A failed check is tried again sooner than a successful one.
            Cache::put(self::CACHE_KEY, $release, $release === false ? now()->addHour() : now()->addHours(self::CACHE_HOURS));
        }

        return $release === false ? null : $release;
    }

    /** The last release found, without asking GitHub; null when none is known. */
    public function cached(): ?array
    {
        if (! config('updates.enabled')) {
            return null;
        }

        return Cache::get(self::CACHE_KEY) ?: null;
    }

    private function fetch(): ?array
    {
        $url = rtrim((string) config('updates.api_url'), '/').'/repos/'.config('updates.repository').'/releases/latest';

        try {
            $response = Http::acceptJson()->withHeaders(['User-Agent' => 'CMS/'.Version::current()])->timeout(10)->get($url)->throw();
        } catch (Throwable) {
            return null;
        }

        $tag = $response->json('tag_name');
        $version = is_string($tag) ? Version::clean($tag) : '';

        // Only plain version numbers, such as 1.2.0 or 1.3.0-beta.1, count as releases.
        if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version)) {
            return null;
        }

        $asset = (string) config('updates.asset');
        $assets = collect((array) $response->json('assets'))->keyBy('name');

        return [
            'version' => $version,
            'name' => (string) ($response->json('name') ?: $response->json('tag_name')),
            'notes' => (string) $response->json('body'),
            'url' => (string) $response->json('html_url'),
            'published_at' => $response->json('published_at'),
            'download_url' => $assets->get($asset)['browser_download_url'] ?? null,
            'checksum_url' => $assets->get($asset.'.sha256')['browser_download_url'] ?? null,
        ];
    }
}
