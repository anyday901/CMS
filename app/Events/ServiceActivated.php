<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

class ServiceActivated
{
    use Dispatchable;

    public function __construct(public Service $service) {}
}
