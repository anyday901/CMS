<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Mail\ClientMailer;
use App\Models\Concerns\HasAffiliateProgram;
use App\Models\Concerns\HasBillingRecords;
use App\Models\Concerns\HasSavedPaymentMethods;
use App\Models\Concerns\HasTwoFactor;
use App\Models\Concerns\OwnsServices;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Client extends Authenticatable
{
    use HasAffiliateProgram, HasBillingRecords, HasFactory, HasSavedPaymentMethods, HasTwoFactor, Notifiable, OwnsServices;

    protected $fillable = [
        'first_name', 'last_name', 'company', 'email', 'password', 'phone',
        'address1', 'address2', 'city', 'state', 'postcode', 'country',
        'currency', 'tax_exempt', 'status', 'notes', 'custom_fields', 'client_group_id', 'language',
    ];

    protected $hidden = ['password', 'legacy_password', 'remember_token'];

    protected $attributes = [
        'status' => 'active',
        'credit_balance' => 0,
        'auto_charge' => true,
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
            'sms_notifications' => 'boolean',
            'gateway_customers' => 'array',
            'auto_charge' => 'boolean',
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

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
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

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
