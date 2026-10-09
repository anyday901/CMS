<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'billing_cycle', 'currency', 'price', 'setup_fee'];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'price' => 'integer',
            'setup_fee' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
