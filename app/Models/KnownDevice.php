<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A browser that has signed in to the portal with an email, known by a
 * random token in a long-lived cookie. Only a hash of the token is kept.
 * Devices unused for a year are forgotten, except an email's most recent
 * one, so a sign-in after a long break still counts as a new device rather
 * than a first one.
 */
class KnownDevice extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = ['email', 'token_hash', 'user_agent', 'ip_address', 'last_used_at', 'created_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('last_used_at', '<', now()->subYear())
            ->whereExists(fn ($newer) => $newer->from('known_devices as newer')
                ->whereColumn('newer.email', 'known_devices.email')
                ->whereColumn('newer.last_used_at', '>', 'known_devices.last_used_at'));
    }
}
