<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One checkout from the cart: the services it created and their first invoice. */
class Order extends Model
{
    protected $fillable = ['client_id', 'invoice_id', 'promotion_id', 'promotion_code', 'ip_address'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
