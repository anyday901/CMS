<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Mail\ClientMailer;
use App\Models\Concerns\HasTwoFactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * One person can be a contact with portal access on several accounts, like
 * a WHMCS 8 user: each account has its own contact, with its own name,
 * emails and permissions, and contacts with the same login email share one
 * login (password, two-factor and remember-me), kept the same on every row.
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

    /** The login itself, the same on every account the person signs in to. */
    public const SHARED_LOGIN = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step'];

    /** Set when saving joined a login the person already had on another account. */
    public bool $joinedLogin = false;

    protected static function booted(): void
    {
        static::saving(function (ClientContact $contact) {
            // A new email is a new login: the old one's password and two-factor stay with the old email.
            if ($contact->exists && $contact->isDirty('email')) {
                $contact->forceFill(array_fill_keys(self::SHARED_LOGIN, null));
            }

            // Given portal access with an email that already signs in elsewhere: use that login.
            if ($contact->portal_access && (! $contact->exists || $contact->isDirty(['portal_access', 'email']))) {
                $contact->joinLogin();
            }
        });

        // A new password, two-factor change or remember token reaches every account of the login.
        static::saved(function (ClientContact $contact) {
            $changed = array_intersect(self::SHARED_LOGIN, array_keys($contact->getChanges()));

            if ($contact->portal_access && $changed !== []) {
                $contact->siblings()->toBase()->update(array_intersect_key($contact->getAttributes(), array_flip($changed)));
            }
        });
    }

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

    /** Every contact with portal access that signs in with this email, on any account. */
    public static function loginsFor(string $email): Builder
    {
        return static::where('email', $email)->where('portal_access', true);
    }

    /** This login's contacts on other accounts. */
    public function siblings(): Builder
    {
        return static::loginsFor((string) $this->email)->whereKeyNot($this->getKey());
    }

    /**
     * The contacts this login can switch between, one per account that can
     * sign in, by account name.
     *
     * @return Collection<int, self>
     */
    public function accounts(): Collection
    {
        return static::loginsFor((string) $this->email)
            ->whereHas('client', fn (Builder $query) => $query->where('status', '!=', ClientStatus::Closed))
            ->with('client')->get()
            ->sortBy(fn (self $contact) => strtolower($contact->accountName()))
            ->values();
    }

    public function hasOtherAccounts(): bool
    {
        return $this->siblings()->whereHas('client', fn (Builder $query) => $query->where('status', '!=', ClientStatus::Closed))->exists();
    }

    /** The account's name, as the contact sees it: its company, or the account holder's name. */
    public function accountName(): string
    {
        return $this->client->company ?: $this->client->fullName();
    }

    /** Copies the login from the person's contact on another account, if they have one with a password. */
    private function joinLogin(): void
    {
        $login = $this->siblings()->whereNotNull('password')->first();

        if ($login === null) {
            return;
        }

        $this->setRawAttributes(array_merge($this->getAttributes(), array_intersect_key($login->getAttributes(), array_flip(self::SHARED_LOGIN))));
        $this->joinedLogin = true;
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

        // A login email can't be a client's. Another account's contact with it is the same person, sharing one login.
        if ($request?->boolean('portal_access')) {
            $rules['email'][] = Rule::unique('clients', 'email');
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
