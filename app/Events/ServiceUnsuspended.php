<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ServiceUnsuspended implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Service $service) {}
}
