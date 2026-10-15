<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A browser that has signed in to the portal with an email, known by a
 * random token in a long-lived cookie. Only a hash of the token is kept.
 * Devices unused for a year are forgotten.
 */
class KnownDevice extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['email', 'token_hash', 'user_agent', 'ip_address', 'last_used_at', 'created_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('last_used_at', '<', now()->subYear());
    }
}
