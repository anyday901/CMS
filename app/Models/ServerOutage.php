<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A time a monitored server was down, or in warning, from when it was confirmed until it recovered. */
class ServerOutage extends Model
{
    protected $fillable = ['server_id', 'status', 'detail', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
