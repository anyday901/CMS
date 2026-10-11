<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Another person on a client's account, such as a bookkeeper or a developer.
 * Contacts can get copies of invoice emails, support emails or both, and a
 * contact who gets support emails can open and reply to tickets by email.
 * They can't sign in to the portal.
 */
class ClientContact extends Model
{
    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'invoice_emails', 'support_emails'];

    protected function casts(): array
    {
        return ['invoice_emails' => 'boolean', 'support_emails' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** What the contact gets, for lists. */
    public function receives(): string
    {
        return match (true) {
            $this->invoice_emails && $this->support_emails => 'Invoice and support emails',
            $this->invoice_emails => 'Invoice emails',
            $this->support_emails => 'Support emails',
            default => 'No emails',
        };
    }

    /** @return array<string, list<mixed>> */
    public static function rules(Client $client, ?self $contact = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::notIn([$client->email]),
                Rule::unique('client_contacts')->where('client_id', $client->id)->ignore($contact)],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** The validated fields plus the two checkboxes. */
    public static function attributesFrom(array $validated, Request $request): array
    {
        return $validated + [
            'invoice_emails' => $request->boolean('invoice_emails'),
            'support_emails' => $request->boolean('support_emails'),
        ];
    }
}
