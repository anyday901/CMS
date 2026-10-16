# SMS providers

Text messages go through a pluggable provider. CMS ships with Twilio, Vonage, and **Log only**, which writes each message to the app log instead of sending it. Staff pick the provider under **Settings > SMS**, enter its credentials and the number or sender ID to send from, and choose which events text staff and clients (see [Webhooks and notifications](../webhooks.md#sms)).

The code is in `app/Integrations/Sms`:

| Class | What it is |
|---|---|
| `SmsProvider` | The interface a provider implements |
| `SmsFailed` | The exception a provider throws when a message isn't accepted |
| `Sms` | The configured provider, phone number clean-up, and queuing texts with a log |
| `SmsNotifier` | The [hook subscriber](hooks.md#subscribers-webhooks-chat-and-sms) that texts staff and clients about events |
| `Providers/Twilio`, `Providers/Vonage`, `Providers/LogOnly` | The built-in providers |

## The `SmsProvider` interface

```php
namespace App\Integrations\Sms;

interface SmsProvider
{
    /** A short unique key, such as "twilio". */
    public function key(): string;

    public function name(): string;

    /**
     * Credentials staff fill in, by name: label, and whether it is secret
     * (kept encrypted and not shown again).
     *
     * @return array<string, array{label: string, secret?: bool, help?: string}>
     */
    public function fields(): array;

    /**
     * Sends one message and returns the provider's id for it.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws SmsFailed when the provider refuses it or can't be reached
     */
    public function send(string $to, string $message, string $from, array $credentials): ?string;
}
```

- **`key()`** is saved as the chosen provider and on each logged text. Keep it stable.
- **`name()`** is shown in the provider list under **Settings > SMS**.
- **`fields()`** are the credential fields shown for the provider. Fields marked `secret` aren't shown again once saved, and a blank secret field keeps the saved value. All the credentials are saved together, encrypted.
- **`send()`** gets the number in international form (such as `+15551234567`), the message, the sending number or sender ID staff entered, and the saved credentials by field name. Return the provider's message id (saved on the log as `provider_reference`), or null if it has none. Throw `SmsFailed` with a message staff can act on when the provider refuses the message or can't be reached.

## Registering a provider

List the class in `config/sms.php`:

```php
'providers' => [
    Twilio::class,
    Vonage::class,
    LogOnly::class,
    // App\Sms\MyProvider::class,
],
```

The other keys in that file (`provider`, `from`, `credentials`, `default_country_code`, `staff_numbers`, `staff_events` and `client_events`) are set by staff under **Settings > SMS**, and saved in the database; `SMS_PROVIDER`, `SMS_FROM` and `SMS_DEFAULT_COUNTRY_CODE` in `.env` only give their starting values.

## An example provider

Twilio's, from `Providers/Twilio.php`:

```php
namespace App\Integrations\Sms\Providers;

use App\Integrations\Sms\SmsFailed;
use App\Integrations\Sms\SmsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
```

Some providers answer HTTP 200 for a refused message: Vonage's `send()` checks each message's own `status` as well.

## How texts are sent

You don't call a provider directly. `App\Integrations\Sms\Sms::queue($to, $message, $clientId, $event)`:

1. cleans up the number with `Sms::normalise()`: numbers starting with `+` or `00` are international, and others are read as national numbers, losing their trunk prefix and getting the default country code;
2. returns null when SMS isn't set up (no provider or sending number) or the number can't be a phone number;
3. saves an `SmsLog` row with the message (cut to 640 characters), queued;
4. dispatches `App\Jobs\SendSms`, which runs after the current database transaction commits.

`SendSms` calls the provider that was chosen when the text was logged. On success the log is marked sent with the provider's id; on `SmsFailed` it is marked failed with the error and tried again after 2 and then 4 minutes, three tries in all. Any other exception is left to the queue. The log is listed under **Settings > SMS** and kept 90 days.

Add-ons can text through the same path, so the log and retries apply:

```php
app(\App\Integrations\Sms\Sms::class)->queue('+15551234567', 'The status board is down.');
```

## Testing a provider

Fake the provider's API and send through `send()`, or through **Send a test** under **Settings > SMS**, which queues a text to one number:

```php
Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

$id = (new Twilio)->send('+15551234567', 'Hello', '+15557654321', ['account_sid' => 'AC1', 'auth_token' => 'secret']);

$this->assertSame('SM123', $id);
Http::assertSent(fn ($request) => $request['To'] === '+15551234567' && $request['Body'] === 'Hello');
```

Also check that a refused message and a connection failure throw `SmsFailed`. `tests/Feature/Integrations/NotificationsTest.php` covers the settings page, encrypted credentials and texts for events.
