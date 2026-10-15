<?php

namespace App\Models;

use App\Mail\ClientMailer;
use App\Models\Concerns\HasTwoFactor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Another person on a client's account, such as a bookkeeper or a developer.
 * Contacts can get copies of invoice emails, support emails or both, and a
 * contact who gets support emails can open and reply to tickets by email.
 * With portal access, they sign in to the portal with their own email and
 * password and see only the parts of the account they're allowed to.
 */
class ClientContact extends Authenticatable
{
    use HasTwoFactor;

    /** What a contact with portal access can be allowed to do. */
    public const PERMISSIONS = [
        'billing' => 'Invoices: see and pay invoices, and use account credit',
        'orders' => 'Orders: place orders, and upgrade or cancel services',
        'services' => 'Services: see services, log in to their control panels and change their passwords',
        'support' => 'Support: open, read and reply to tickets',
    ];

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'invoice_emails', 'support_emails', 'portal_access', 'permissions'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'invoice_emails' => 'boolean',
            'support_emails' => 'boolean',
            'portal_access' => 'boolean',
            'permissions' => 'array',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function loginAttempts(): MorphMany
    {
        return $this->morphMany(LoginAttempt::class, 'account');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Contacts have no remember-me: a remembered login would come back as the account owner. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function allows(string $permission): bool
    {
        return $this->portal_access && in_array($permission, $this->permissions ?? [], true);
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

    /** What the contact can do in the portal, for lists. */
    public function access(): string
    {
        if (! $this->portal_access) {
            return 'No portal login';
        }

        $allowed = array_values(array_intersect(array_keys(self::PERMISSIONS), $this->permissions ?? []));
        $what = $allowed === [] ? 'nothing yet' : implode(', ', $allowed);

        return ($this->password === null ? 'Invited to sign in: ' : 'Signs in: ').$what;
    }

    public function sendPasswordResetNotification($token): void
    {
        app(ClientMailer::class)->sendToContact($this, 'password_reset', [
            'reset_url' => route('portal.password.reset', ['token' => $token, 'email' => $this->email]),
            'expire_minutes' => config('auth.passwords.contacts.expire'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public static function rules(Client $client, ?self $contact = null, ?Request $request = null): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::notIn([$client->email]),
                Rule::unique('client_contacts')->where('client_id', $client->id)->ignore($contact)],
            'phone' => ['nullable', 'string', 'max:50'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(array_keys(self::PERMISSIONS))],
        ];

        // A login email has to point at one person: no client, and no other contact who signs in.
        if ($request?->boolean('portal_access')) {
            $rules['email'][] = Rule::unique('clients', 'email');
            $rules['email'][] = Rule::unique('client_contacts', 'email')->where('portal_access', true)->ignore($contact);
        }

        return $rules;
    }

    /** Clients' emails can't be the email a contact signs in with. */
    public static function loginEmailRule(): Unique
    {
        return Rule::unique('client_contacts', 'email')->where('portal_access', true);
    }

    /** The validated fields plus the checkboxes. */
    public static function attributesFrom(array $validated, Request $request): array
    {
        return collect($validated)->except('permissions')->all() + [
            'invoice_emails' => $request->boolean('invoice_emails'),
            'support_emails' => $request->boolean('support_emails'),
            'portal_access' => $request->boolean('portal_access'),
            'permissions' => array_values(array_intersect(array_keys(self::PERMISSIONS), (array) ($validated['permissions'] ?? []))),
        ];
    }
}
