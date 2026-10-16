<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\Domain;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a client has bought: their orders, and the services and domains they
 * bought.
 *
 * @mixin Client
 */
trait OwnsServices
{
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
