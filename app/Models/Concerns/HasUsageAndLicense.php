<?php

namespace App\Models\Concerns;

use App\Models\License;
use App\Models\ServiceUsage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A service's usage readings and software license, which some modules keep. */
trait HasUsageAndLicense
{
    /** Disk and bandwidth use by month, for modules whose servers report it. */
    public function usages(): HasMany
    {
        return $this->hasMany(ServiceUsage::class);
    }

    /** The latest month's use. */
    public function currentUsage(): HasOne
    {
        return $this->hasOne(ServiceUsage::class)->latestOfMany('period');
    }

    /** The software license the licensing module issued for the service. */
    public function license(): HasOne
    {
        return $this->hasOne(License::class);
    }
}
