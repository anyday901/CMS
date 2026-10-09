<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

class ServiceUnsuspended
{
    use Dispatchable;

    public function __construct(public Service $service) {}
}
