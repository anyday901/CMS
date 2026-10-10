<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;

/**
 * Settings staff can change in the admin area. Each one is a config key; a
 * saved value replaces what config/.env says, and the rest fall back to it.
 */
class Settings
{
    private const CACHE_KEY = 'settings';

    /** Config keys that can be saved here. */
    public const KEYS = [
        'billing.late_fee_after_days',
        'billing.late_fee_type',
        'billing.late_fee_amount',
        'billing.tax_inclusive',
    ];

    /** Copies saved settings over the config. Called once per request at boot. */
    public static function apply(): void
    {
        try {
            $saved = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::pluck('value', 'key')->all());
        } catch (QueryException|PDOException|InvalidArgumentException) {
            // No database yet (fresh install, composer scripts) or not migrated.
            return;
        }

        foreach (array_intersect_key($saved, array_flip(self::KEYS)) as $key => $value) {
            config([$key => $key === 'billing.tax_inclusive' ? (bool) $value : $value]);
        }
    }

    /** @param  array<string, string|int|bool|null>  $values */
    public static function save(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? (int) $value : $value]);
                config([$key => $value]);
            }
        });

        Cache::forget(self::CACHE_KEY);
    }
}
