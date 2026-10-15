<?php

namespace App\Integrations\Sms\Providers;

use App\Integrations\Sms\SmsFailed;
use App\Integrations\Sms\SmsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Twilio's Messages API. */
class Twilio implements SmsProvider
{
    public function key(): string
    {
        return 'twilio';
    }

    public function name(): string
    {
        return 'Twilio';
    }

    public function fields(): array
    {
        return [
            'account_sid' => ['label' => 'Account SID'],
            'auth_token' => ['label' => 'Auth token', 'secret' => true],
        ];
    }

    public function send(string $to, string $message, string $from, array $credentials): ?string
    {
        $sid = $credentials['account_sid'] ?? '';

        try {
            $response = Http::asForm()->timeout(15)
                ->withBasicAuth($sid, $credentials['auth_token'] ?? '')
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json', [
                    'To' => $to,
                    // A messaging service SID (MG…) sends from its own pool of numbers.
                    str_starts_with($from, 'MG') ? 'MessagingServiceSid' : 'From' => $from,
                    'Body' => $message,
                ]);
        } catch (ConnectionException $e) {
            throw new SmsFailed("Twilio couldn't be reached: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new SmsFailed('Twilio refused the message: '.($response->json('message') ?? "HTTP {$response->status()}"));
        }

        return $response->json('sid');
    }
}
