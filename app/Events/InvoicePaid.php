<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InvoicePaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Invoice $invoice) {}
}
