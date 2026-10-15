<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
        'billing.attach_invoice_pdf',
        'billing.hold_new_orders',
        'activity.retention_days',
        'tickets.close_inactive_hours',
        'tickets.business_hours',
        'tickets.holidays',
        'tickets.timezone',
        'tickets.guest_tickets',
        'tickets.spam.use_spam_headers',
        'tickets.spam.blocked_senders',
        'tickets.spam.blocked_subjects',
        'tickets.spam.blocked_phrases',
        'tickets.spam.new_tickets_per_hour',
        'help.main_language',
        'help.languages',
        'security.lockout_attempts',
        'security.lockout_minutes',
        'security.ban_after_failures',
        'security.ban_hours',
        'security.login_history_days',
        'security.staff_two_factor',
        'security.new_device_emails',
        'security.captcha.provider',
        'security.captcha.site_key',
        'security.captcha.secret_key',
        'security.captcha.forms',
    ];

    /** Saved as 0 or 1, read back as booleans. */
    private const BOOLEANS = ['billing.tax_inclusive', 'billing.attach_invoice_pdf', 'billing.hold_new_orders', 'tickets.guest_tickets', 'tickets.spam.use_spam_headers', 'security.staff_two_factor', 'security.new_device_emails'];

    /** Saved as JSON, read back as arrays. */
    private const ARRAYS = [
        'tickets.business_hours', 'tickets.holidays', 'tickets.spam.blocked_senders', 'tickets.spam.blocked_subjects', 'tickets.spam.blocked_phrases', 'help.languages', 'security.captcha.forms',
    ];

    /** Saved encrypted, read back decrypted. */
    private const SECRETS = ['security.captcha.secret_key'];

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
            config([$key => match (true) {
                in_array($key, self::SECRETS, true) => self::decrypt($value),
                in_array($key, self::BOOLEANS, true) => (bool) $value,
                in_array($key, self::ARRAYS, true) => (array) json_decode((string) $value, true),
                default => $value,
            }]);
        }
    }

    /** @param  array<string, string|int|bool|array<mixed>|null>  $values */
    public static function save(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => match (true) {
                    in_array($key, self::SECRETS, true) => filled($value) ? Crypt::encryptString((string) $value) : null,
                    is_bool($value) => (int) $value,
                    is_array($value) => json_encode($value),
                    default => $value,
                }]);
                config([$key => $value]);
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /** A secret saved with another APP_KEY can't be read, and counts as not set. */
    private static function decrypt(?string $value): ?string
    {
        try {
            return filled($value) ? Crypt::decryptString($value) : null;
        } catch (DecryptException) {
            return null;
        }
    }
}
