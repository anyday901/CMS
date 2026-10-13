<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Servers a product's new services can go on. The fill type decides which
 * one each new service gets.
 */
class ServerGroup extends Model
{
    /** The server with the lowest share of its accounts in use. */
    public const LEAST_USED = 'least_used';

    /** The fullest server that still has room, so one fills before the next is used. */
    public const FILL = 'fill';

    public const FILL_TYPES = [
        self::LEAST_USED => 'Least used: spread new accounts, each on the server with the most room',
        self::FILL => 'Fill up: fill one server before using the next',
    ];

    protected $fillable = ['name', 'fill_type'];

    protected $attributes = ['fill_type' => self::LEAST_USED];

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_group_server')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function fillLabel(): string
    {
        return $this->fill_type === self::FILL ? 'Fill up' : 'Least used';
    }
}
