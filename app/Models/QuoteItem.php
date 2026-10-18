<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    protected $fillable = ['quote_id', 'description', 'amount', 'taxable'];

    protected $attributes = ['taxable' => true];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'taxable' => 'boolean',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
