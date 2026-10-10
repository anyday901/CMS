<?php

use App\Billing\InvoiceGenerator;
use App\Billing\LateFeeProcessor;
use App\Billing\OverdueProcessor;
use App\Billing\RefundReconciler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('billing:run', function (InvoiceGenerator $generator, LateFeeProcessor $lateFees, OverdueProcessor $overdue, RefundReconciler $refunds) {
    $invoices = $generator->generate(today());
    $this->info("Generated {$invoices->count()} invoice(s).");

    $charged = $lateFees->run(today());
    $this->info("Added late fees to {$charged} invoice(s).");

    $counts = $overdue->run(today());
    $this->info("Suspended {$counts['suspended']} and terminated {$counts['terminated']} service(s).");

    $settled = $refunds->run();
    $this->info("Pending refunds: {$settled['completed']} completed, {$settled['failed']} failed.");
})->purpose('Generate renewal invoices, add late fees, process overdue services and check pending refunds');

Schedule::command('billing:run')->dailyAt('00:05')->withoutOverlapping();
