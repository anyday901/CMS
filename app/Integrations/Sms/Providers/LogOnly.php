<?php

namespace App\Integrations\Sms\Providers;

use App\Integrations\Sms\SmsProvider;
use Illuminate\Support\Facades\Log;

/** Writes messages to the app log instead of sending them, for trying SMS out. */
class LogOnly implements SmsProvider
{
    public function key(): string
    {
        return 'log';
    }

    public function name(): string
    {
        return 'Log only (for testing)';
    }

    public function fields(): array
    {
        return [];
    }

    public function send(string $to, string $message, string $from, array $credentials): ?string
    {
        Log::info("SMS to {$to} from {$from}: {$message}");

        return null;
    }
}
