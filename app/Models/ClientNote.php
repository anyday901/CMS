<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff note on a client. Pinned notes show at the top of the client's page. */
class ClientNote extends Model
{
    protected $fillable = ['body', 'pinned'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
