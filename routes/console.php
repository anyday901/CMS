<?php

use App\Billing\InvoiceGenerator;
use App\Billing\OverdueProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('billing:run', function (InvoiceGenerator $generator, OverdueProcessor $overdue) {
    $invoices = $generator->generate(today());
    $this->info("Generated {$invoices->count()} invoice(s).");

    $counts = $overdue->run(today());
    $this->info("Suspended {$counts['suspended']} and terminated {$counts['terminated']} service(s).");
})->purpose('Generate renewal invoices and process overdue services');

Schedule::command('billing:run')->dailyAt('00:05')->withoutOverlapping();
