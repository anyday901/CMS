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
        $oldName = $client->fullName();
        $oldEmail = $client->email;
        $files = [];

        DB::transaction(function () use ($client, $oldEmail, &$files) {
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
            $client->orders()->update(['ip_address' => null]);
            $client->services()->update(['custom_fields' => null, 'username' => null, 'password' => null, 'new_password' => null]);
            CancellationRequest::whereIn('service_id', $client->services()->select('id'))->update(['reason' => null]);

            $tickets = $client->tickets()->select('id');
            $messages = TicketMessage::whereIn('ticket_id', $tickets);
            $attachments = TicketAttachment::whereIn('ticket_message_id', (clone $messages)->select('id'));
            $files = $attachments->pluck('path')->all();
            $attachments->delete();
            $messages->update(['body' => self::REMOVED, 'author_name' => null, 'email_message_id' => null]);
            $client->tickets()->update(['subject' => 'Anonymised ticket']);
            TicketFeedback::whereIn('ticket_id', $tickets)->update(['comment' => null]);

            Activity::where('client_id', $client->id)->update(['ip_address' => null, 'properties' => null]);
            InboundEmail::where('from', 'like', '%'.addcslashes((string) $oldEmail, '%_\\').'%')->delete();
        });

        Storage::disk('local')->delete($files);
        $this->scrubActivity($client, $oldName);
        Activity::record("Anonymised client #{$client->id}", $client);
    }

    /** Takes the client's old name out of their activity log entries. */
    private function scrubActivity(Client $client, string $oldName): void
    {
        if (trim($oldName) === '') {
            return;
        }

        Activity::where('client_id', $client->id)
            ->where(fn ($query) => $query->where('description', 'like', '%'.addcslashes($oldName, '%_\\').'%')->orWhere('actor_name', 'like', $oldName.'%'))
            ->each(function (Activity $entry) use ($oldName, $client) {
                $entry->forceFill([
                    'description' => Str::limit(str_replace($oldName, $client->fullName(), $entry->description), 250, ''),
                    'actor_name' => str_starts_with($entry->actor_name, $oldName) ? $client->fullName() : $entry->actor_name,
                ])->save();
            });
    }
}
