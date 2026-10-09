<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

class ServiceSuspended
{
    use Dispatchable;

    public function __construct(public Service $service) {}
}
