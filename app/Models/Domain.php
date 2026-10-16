<?php

namespace App\Models;

use App\Domains\DomainContact;
use App\Enums\DomainStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A domain a client registered or transferred with us. */
class Domain extends Model
{
    use HasFactory;

    public const REGISTER = 'register';

    public const TRANSFER = 'transfer';

    protected $fillable = [
        'client_id', 'order_id', 'tld_id', 'name', 'registrar', 'type', 'status', 'years', 'recurring_amount',
        'registration_date', 'expiry_date', 'next_due_date', 'auto_renew', 'id_protection', 'locked', 'nameservers', 'epp_code', 'contact',
    ];

    protected $attributes = ['status' => 'pending', 'type' => self::REGISTER];

    protected $hidden = ['epp_code'];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'years' => 'integer',
            'recurring_amount' => 'integer',
            'registration_date' => 'date',
            'expiry_date' => 'date',
            'next_due_date' => 'date',
            'auto_renew' => 'boolean',
            'id_protection' => 'boolean',
            'locked' => 'boolean',
            'nameservers' => 'array',
            'epp_code' => 'encrypted',
            'contact' => 'array',
            'registrar_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function tld(): BelongsTo
    {
        return $this->belongsTo(Tld::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @param  Builder<Domain>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', DomainStatus::live());
    }

    /** "example" for example.co.uk, given its TLD. */
    public function sld(): string
    {
        $extension = $this->extension();

        return $extension === '' ? $this->name : substr($this->name, 0, -strlen($extension) - 1);
    }

    /** "co.uk" for example.co.uk: the TLD it was ordered under, else everything after the first dot. */
    public function extension(): string
    {
        $extension = $this->tld?->extension;

        if ($extension !== null && str_ends_with($this->name, '.'.$extension)) {
            return $extension;
        }

        return str_contains($this->name, '.') ? substr($this->name, strpos($this->name, '.') + 1) : '';
    }

    /** The registrant: the details saved for the domain, else the client's. */
    public function registrant(): DomainContact
    {
        return $this->contact ? DomainContact::fromArray($this->contact) : DomainContact::fromClient($this->client);
    }

    /** Whether staff or the client can manage it at the registrar. */
    public function isManageable(): bool
    {
        return in_array($this->status, DomainStatus::live(), true) && $this->registrar !== null;
    }

    /** One value the registrar module keeps for the domain. */
    public function registrarValue(string $key): mixed
    {
        return ($this->registrar_data ?? [])[$key] ?? null;
    }

    /** Saves values the registrar module keeps for the domain. */
    public function rememberRegistrarValues(array $values): void
    {
        $this->forceFill(['registrar_data' => array_merge($this->registrar_data ?? [], $values)])->save();
    }

    /** Days until it expires; negative once it has. */
    public function daysLeft(): ?int
    {
        return $this->expiry_date ? (int) today()->diffInDays($this->expiry_date, false) : null;
    }
}
