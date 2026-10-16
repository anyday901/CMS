<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What registering, transferring and renewing a TLD for a number of years costs in one currency. */
class TldPrice extends Model
{
    public const TYPES = ['register', 'transfer', 'renew'];

    protected $fillable = ['tld_id', 'currency', 'years', 'register', 'transfer', 'renew', 'id_protection'];

    protected function casts(): array
    {
        return [
            'years' => 'integer',
            'register' => 'integer',
            'transfer' => 'integer',
            'renew' => 'integer',
            'id_protection' => 'integer',
        ];
    }

    public function tld(): BelongsTo
    {
        return $this->belongsTo(Tld::class);
    }
}
