<?php

namespace App\Mail;

use App\Http\Controllers\MarketingUnsubscribeController;
use App\Jobs\SendLoggedEmail;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\MassMail;
use Illuminate\Support\Str;

/**
 * Fills in a mass mail for one client and queues it. Marketing emails get
 * the client's unsubscribe link at the bottom and in the List-Unsubscribe
 * header, so mail apps can offer one-click unsubscribe.
 */
class MassMailer
{
    /** Placeholders a mass mail can use. */
    public const PLACEHOLDERS = ['client_first_name', 'client_name', 'company_name', 'portal_url', 'unsubscribe_url'];

    /** @return array{subject: string, body: string, unsubscribe: ?string} */
    public function render(MassMail $mail, Client $client): array
    {
        $unsubscribe = $mail->marketing ? MarketingUnsubscribeController::url($client) : null;
        $company = (string) (config('billing.company.name') ?: config('app.name'));
        $values = [
            'client_first_name' => $client->first_name,
            'client_name' => $client->fullName(),
            'company_name' => $company,
            'portal_url' => route('portal.dashboard'),
            'unsubscribe_url' => $unsubscribe ?? '',
        ];
        $fill = fn (string $text) => preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m) => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0], $text);

        $body = $fill($mail->body);
        if ($unsubscribe !== null) {
            $body .= "\n\n---\n\nYou're getting this because you agreed to news and offers from {$company}. [Unsubscribe]({$unsubscribe}) at any time.";
        }

        return [
            'subject' => Str::limit(str_replace(["\r", "\n"], ' ', $fill($mail->subject)), 250, ''),
            'body' => $body,
            'unsubscribe' => $unsubscribe,
        ];
    }

    public function queue(MassMail $mail, Client $client): EmailLog
    {
        $rendered = $this->render($mail, $client);

        $log = EmailLog::create([
            'client_id' => $client->id,
            'mass_mail_id' => $mail->id,
            'template' => 'mass_mail',
            'to' => $client->email,
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'list_unsubscribe' => $rendered['unsubscribe'],
            'status' => EmailLog::QUEUED,
        ]);

        SendLoggedEmail::dispatch($log->id);

        return $log;
    }
}
