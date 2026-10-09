<?php

namespace App\Models;

use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Client extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'first_name', 'last_name', 'company', 'email', 'password', 'phone',
        'address1', 'address2', 'city', 'state', 'postcode', 'country',
        'currency', 'status', 'notes',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'status' => 'active',
        'credit_balance' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->currency ??= config('billing.currency');
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => ClientStatus::class,
            'credit_balance' => 'integer',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
