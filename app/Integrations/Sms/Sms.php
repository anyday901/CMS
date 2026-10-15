<?php

namespace App\Integrations\Sms;

use App\Jobs\SendSms;
use App\Models\SmsLog;
use Illuminate\Support\Collection;

/** The configured text message provider, phone number clean-up, and queuing texts with a log. */
class Sms
{
    /** @return Collection<string, SmsProvider> */
    public function providers(): Collection
    {
        return collect(config('sms.providers', []))
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (SmsProvider $provider) => $provider->key());
    }

    public function provider(): ?SmsProvider
    {
        $key = config('sms.provider');

        return $key ? $this->providers()->get($key) : null;
    }

    /** Whether a provider and a sending number are set. */
    public function configured(): bool
    {
        return $this->provider() !== null && filled(config('sms.from'));
    }

    /** @return array<string, string> */
    public function credentials(): array
    {
        $saved = json_decode((string) config('sms.credentials'), true);

        return is_array($saved) ? array_map('strval', $saved) : [];
    }

    /**
     * A phone number in international form (+15551234567), or null when it
     * can't be one. Numbers without a country code get the default one.
     */
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $phone);

        if ($digits === '' || $digits === '+') {
            return null;
        }

        $number = match (true) {
            str_starts_with($digits, '+') => '+'.ltrim($digits, '+'),
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            default => self::withCountryCode($digits),
        };

        return preg_match('/^\+[1-9]\d{6,14}$/', $number) ? $number : null;
    }

    private static function withCountryCode(string $digits): string
    {
        $code = preg_replace('/\D/', '', (string) config('sms.default_country_code'));

        // A number already starting with the code and long enough to include it, such as 1 555 123 4567.
        if ($code !== '' && str_starts_with($digits, $code) && strlen($digits) > 10) {
            return '+'.$digits;
        }

        return '+'.$code.ltrim($digits, '0');
    }

    /** Logs a text and queues it. Returns null when SMS isn't set up or the number isn't usable. */
    public function queue(string $to, string $message, ?int $clientId = null, ?string $event = null): ?SmsLog
    {
        $number = self::normalise($to);

        if ($number === null || ! $this->configured()) {
            return null;
        }

        $log = SmsLog::create([
            'client_id' => $clientId,
            'event' => $event,
            'provider' => $this->provider()->key(),
            'to' => $number,
            'message' => mb_substr($message, 0, 640),
            'status' => SmsLog::QUEUED,
        ]);

        SendSms::dispatch($log->id);

        return $log;
    }
}
