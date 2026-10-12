<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Mail\ClientMailer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Client extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name', 'last_name', 'company', 'email', 'password', 'phone',
        'address1', 'address2', 'city', 'state', 'postcode', 'country',
        'currency', 'tax_exempt', 'status', 'notes', 'custom_fields',
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
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderBy('first_name')->orderBy('last_name');
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

    public function canLogIn(): bool
    {
        return $this->status !== ClientStatus::Closed;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
