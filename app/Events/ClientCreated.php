<?php

namespace App\Events;

use App\Models\Client;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A new client account: signed up at checkout, added by staff or through the API. */
class ClientCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Client $client) {}
}
