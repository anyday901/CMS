<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A move of a service to another product. One that costs more waits for its
 * invoice to be paid; one that costs the same or less is applied at once.
 */
class ServiceUpgrade extends Model
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    /** Its invoice was cancelled before it was paid. */
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'service_id', 'invoice_id', 'from_product_id', 'to_product_id', 'from_amount', 'to_amount',
        'from_cycle', 'to_cycle', 'next_due_date', 'options', 'prorated_amount', 'status', 'moved_by', 'applied_at',
    ];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'from_amount' => 'integer',
            'to_amount' => 'integer',
            'prorated_amount' => 'integer',
            'next_due_date' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    /** The staff member who made the move, when it wasn't the client. */
    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }
}
