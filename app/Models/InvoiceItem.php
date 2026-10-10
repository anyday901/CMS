<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    public const TYPE_MANUAL = 'manual';

    public const TYPE_SERVICE = 'service';

    public const TYPE_SETUP_FEE = 'setup_fee';

    public const TYPE_LATE_FEE = 'late_fee';

    protected $fillable = [
        'invoice_id', 'service_id', 'type', 'description', 'amount', 'taxable',
        'period_start', 'period_end',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'taxable' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    protected $attributes = ['taxable' => true];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
