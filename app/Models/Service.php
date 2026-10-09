<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'product_id', 'label', 'billing_cycle', 'recurring_amount',
        'status', 'registration_date', 'next_due_date', 'custom_fields',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => ServiceStatus::class,
            'recurring_amount' => 'integer',
            'registration_date' => 'date',
            'next_due_date' => 'date',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'custom_fields' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function description(): string
    {
        return $this->label
            ? "{$this->product->name} - {$this->label}"
            : $this->product->name;
    }
}
