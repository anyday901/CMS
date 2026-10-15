<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Mail\ClientMailer;
use App\Models\Concerns\HasTwoFactor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;

class Client extends Authenticatable
{
    use HasFactory, HasTwoFactor, Notifiable;

    protected $fillable = [
        'first_name', 'last_name', 'company', 'email', 'password', 'phone',
        'address1', 'address2', 'city', 'state', 'postcode', 'country',
        'currency', 'tax_exempt', 'status', 'notes', 'custom_fields', 'client_group_id',
    ];

    protected $hidden = ['password', 'legacy_password', 'remember_token'];

    protected $attributes = [
        'status' => 'active',
        'credit_balance' => 0,
        'tax_exempt' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->currency ??= config('billing.currency');
        });

        // A new password replaces any password brought over from WHMCS.
        static::saving(function (Client $client) {
            if ($client->isDirty('password') && $client->password !== null) {
                $client->legacy_password = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => ClientStatus::class,
            'credit_balance' => 'integer',
            'tax_exempt' => 'boolean',
            'custom_fields' => 'array',
            'email_verified_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'marketing_emails' => 'boolean',
            'marketing_consent_at' => 'datetime',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderBy('first_name')->orderBy('last_name');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class, 'client_group_id');
    }

    public function clientNotes(): HasMany
    {
        return $this->hasMany(ClientNote::class)->orderByDesc('pinned')->latest()->latest('id');
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(CreditEntry::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        app(ClientMailer::class)->send($this, 'password_reset', [
            'reset_url' => route('portal.password.reset', ['token' => $token, 'email' => $this->email]),
            'expire_minutes' => config('auth.passwords.clients.expire'),
        ]);
    }

    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    public function canLogIn(): bool
    {
        return $this->status !== ClientStatus::Closed;
    }

    /**
     * Records the client's answer about news and offers, with when they gave
     * it, as proof of consent. Returns whether it changed.
     */
    public function setMarketingConsent(bool $yes): bool
    {
        if ($this->exists && $this->marketing_emails === $yes) {
            return false;
        }

        $this->forceFill(['marketing_emails' => $yes, 'marketing_consent_at' => now()]);

        return true;
    }

    /** A link that unsubscribes the client from marketing emails, without logging in. */
    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('marketing.unsubscribe', ['client' => $this->id]);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
