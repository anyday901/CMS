<?php

namespace App\Integrations\Sms\Providers;

use App\Integrations\Sms\SmsFailed;
use App\Integrations\Sms\SmsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Vonage (Nexmo) SMS API. */
class Vonage implements SmsProvider
{
    public function key(): string
    {
        return 'vonage';
    }

    public function name(): string
    {
        return 'Vonage';
    }

    public function fields(): array
    {
        return [
            'api_key' => ['label' => 'API key'],
            'api_secret' => ['label' => 'API secret', 'secret' => true],
        ];
    }

    public function send(string $to, string $message, string $from, array $credentials): ?string
    {
        try {
            $response = Http::asForm()->timeout(15)->post('https://rest.nexmo.com/sms/json', [
                'api_key' => $credentials['api_key'] ?? '',
                'api_secret' => $credentials['api_secret'] ?? '',
                'from' => ltrim($from, '+'),
                'to' => ltrim($to, '+'),
                'text' => $message,
                'type' => 'unicode',
            ]);
        } catch (ConnectionException $e) {
            throw new SmsFailed("Vonage couldn't be reached: {$e->getMessage()}");
        }

        // Vonage answers 200 with a status per message part; "0" is success.
        $first = $response->json('messages.0') ?? [];
        if (! $response->successful() || ($first['status'] ?? null) !== '0') {
            throw new SmsFailed('Vonage refused the message: '.($first['error-text'] ?? "HTTP {$response->status()}"));
        }

        return $first['message-id'] ?? null;
    }
}
