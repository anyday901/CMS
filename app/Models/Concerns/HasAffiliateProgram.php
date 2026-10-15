<?php

namespace App\Models\Concerns;

use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\Client;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A client's two sides of the affiliate program: referring others, and being
 * referred.
 *
 * @mixin Client
 */
trait HasAffiliateProgram
{
    /** Their place in the affiliate program, if they joined. */
    public function affiliate(): HasOne
    {
        return $this->hasOne(Affiliate::class);
    }

    /** Which affiliate referred them, if any. */
    public function referral(): HasOne
    {
        return $this->hasOne(AffiliateReferral::class);
    }
}
