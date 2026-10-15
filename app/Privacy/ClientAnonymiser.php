<?php

namespace App\Privacy;

use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\InboundEmail;
use App\Models\LoginAttempt;
use App\Models\TicketAttachment;
use App\Models\TicketFeedback;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Removes a closed client's personal details for good (the GDPR right to
 * erasure), while keeping the records the business has to keep: invoices,
 * payments and services stay, with the client's name, contact details and
 * address gone. Tickets keep their place in reports but lose what was said;
 * emails, contacts and login history are deleted.
 */
class ClientAnonymiser
{
    public const REMOVED = '(Removed when the account was anonymised.)';

    public const TICKET_SUBJECT = 'Anonymised ticket';

    /** Why the client can't be anonymised yet, or null when they can. */
    public function problem(Client $client): ?string
    {
        return match (true) {
            $client->isAnonymised() => 'This client is already anonymised.',
            $client->status !== ClientStatus::Closed => 'Close the account first. Only closed accounts can be anonymised.',
            $client->services()->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])->exists() => 'Terminate or cancel the client\'s services first.',
            default => null,
        };
    }

    public function anonymise(Client $client): void
    {
        $personal = $this->personalText($client);
        $oldEmail = $client->email;
        $emails = $client->contacts()->pluck('email')->push($oldEmail)->filter()->all();
        $files = [];

        DB::transaction(function () use ($client, $oldEmail, $emails, &$files) {
            $client->forceFill([
                'first_name' => 'Anonymised',
                'last_name' => "client {$client->id}",
                'company' => null,
                'email' => "anonymised-{$client->id}@anonymised.invalid",
                'email_verified_at' => null,
                'phone' => null,
                'address1' => null,
                'address2' => null,
                'city' => null,
                'state' => null,
                'postcode' => null,
                'notes' => null,
                'custom_fields' => null,
                'password' => null,
                'legacy_password' => null,
                'remember_token' => null,
                'anonymised_at' => now(),
            ])->save();
            $client->disableTwoFactor();

            $client->contacts()->delete();
            $client->emailLogs()->delete();
            LoginAttempt::where('client_id', $client->id)->delete();
            foreach (['client_password_reset_tokens', 'contact_password_reset_tokens'] as $table) {
                DB::table($table)->whereIn('email', $emails)->delete();
            }
            $client->orders()->update(['ip_address' => null]);
            $client->services()->update(['custom_fields' => null, 'username' => null, 'password' => null, 'new_password' => null]);
            CancellationRequest::whereIn('service_id', $client->services()->select('id'))->update(['reason' => null]);

            $tickets = $client->tickets()->select('id');
            $messages = TicketMessage::whereIn('ticket_id', $tickets);
            $attachments = TicketAttachment::whereIn('ticket_message_id', (clone $messages)->select('id'));
            $files = $attachments->pluck('path')->all();
            $attachments->delete();
            $messages->update(['body' => self::REMOVED, 'author_name' => null, 'email_message_id' => null]);
            $client->tickets()->update(['subject' => self::TICKET_SUBJECT]);
            TicketFeedback::whereIn('ticket_id', $tickets)->update(['comment' => null]);

            Activity::where('client_id', $client->id)->update(['ip_address' => null, 'properties' => null]);
            InboundEmail::where('from', 'like', '%'.addcslashes((string) $oldEmail, '%_\\').'%')->delete();
        });

        Storage::disk('local')->delete($files);
        $this->scrubActivity($client, $personal);
        Activity::record("Anonymised client #{$client->id}", $client);
    }

    /**
     * What the activity log may have picked up about the people on the
     * account, longest first, mapped to what replaces it: names and emails of
     * the client and their contacts, and ticket subjects.
     *
     * @return array<string, string>
     */
    private function personalText(Client $client): array
    {
        $new = "Anonymised client {$client->id}";
        $text = [$client->fullName() => $new, (string) $client->email => $new];

        foreach ($client->contacts as $contact) {
            $text[trim($contact->first_name.' '.$contact->last_name)] = 'a contact';
            $text[(string) $contact->email] = 'a contact';
        }
        foreach ($client->tickets()->pluck('subject') as $subject) {
            $text[(string) $subject] = self::TICKET_SUBJECT;
        }

        $text = array_filter($text, fn ($replacement, $find) => trim((string) $find) !== '', ARRAY_FILTER_USE_BOTH);
        uksort($text, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

        return $text;
    }

    /** Takes the people's names, emails and ticket subjects out of the client's activity log entries. */
    private function scrubActivity(Client $client, array $personal): void
    {
        $find = array_map('strval', array_keys($personal));
        // Who did something is only ever a person's name, never a ticket subject.
        $people = array_map('strval', array_keys(array_filter($personal, fn ($replacement) => $replacement !== self::TICKET_SUBJECT)));

        Activity::where('client_id', $client->id)->lazyById()->each(function (Activity $entry) use ($client, $find, $people, $personal) {
            $description = str_ireplace($find, array_values($personal), (string) $entry->description);
            $actor = $entry->actor_name;
            if ($actor !== null && $actor !== '' && Str::contains($actor, $people, true)) {
                $actor = $client->fullName();
            }

            if ($description !== $entry->description || $actor !== $entry->actor_name) {
                $entry->forceFill(['description' => Str::limit($description, 250, ''), 'actor_name' => $actor])->save();
            }
        });
    }
}
