<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A service moved to another product (an upgrade or downgrade). */
class ServiceChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Service $service) {}
}
