<?php

namespace App\Jobs;

use App\Domains\RegistrarActions;
use App\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Registers, transfers or renews a domain at its registrar. Not retried on
 * its own: a request that failed half way is safer for staff to check and
 * run again from the domain page than to repeat blindly.
 */
class RunRegistrarAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Domain $domain, public string $action, public int $years) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("domain-{$this->domain->id}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(RegistrarActions $actions): void
    {
        $domain = $this->domain->fresh(['client', 'tld']);

        if ($domain !== null) {
            $actions->run($domain, $this->action, $this->years);
        }
    }
}
