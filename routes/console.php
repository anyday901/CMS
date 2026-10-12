<?php

use App\Billing\InvoiceGenerator;
use App\Billing\InvoiceReminders;
use App\Billing\LateFeeProcessor;
use App\Billing\OverdueProcessor;
use App\Billing\RefundReconciler;
use App\Billing\ServiceCanceller;
use App\Models\Activity;
use App\Models\Department;
use App\Tickets\InboundMail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('billing:run', function (ServiceCanceller $cancellations, InvoiceGenerator $generator, LateFeeProcessor $lateFees, OverdueProcessor $overdue, RefundReconciler $refunds, InvoiceReminders $reminders) {
    $cancelled = $cancellations->run(today());
    $this->info("Cancelled {$cancelled} service(s) at the end of their billing period.");

    $invoices = $generator->generate(today());
    $this->info("Generated {$invoices->count()} invoice(s).");

    $charged = $lateFees->run(today());
    $this->info("Added late fees to {$charged} invoice(s).");

    $counts = $overdue->run(today());
    $this->info("Suspended {$counts['suspended']} and terminated {$counts['terminated']} service(s).");

    $reminded = $reminders->run(today());
    $this->info("Sent {$reminded} overdue reminder(s).");

    $settled = $refunds->run();
    $this->info("Pending refunds: {$settled['completed']} completed, {$settled['failed']} failed.");
})->purpose('Carry out cancellation requests, generate renewal invoices, add late fees, send overdue reminders, process overdue services and check pending refunds');

Artisan::command('activity:prune', function () {
    $days = config('activity.retention_days');

    if (blank($days)) {
        $this->info('Activity log retention is off; nothing deleted.');

        return;
    }

    $deleted = Activity::where('created_at', '<', now()->subDays((int) $days))->delete();
    $this->info("Deleted {$deleted} activity log entr".($deleted === 1 ? 'y' : 'ies')." older than {$days} days.");
})->purpose('Delete activity log entries older than the retention setting');

Artisan::command('tickets:pipe {file? : Read the email from this file instead of standard input} {--department= : Department id for new tickets, when the address the email was sent to is not a department address}', function (InboundMail $inbound) {
    $department = $this->option('department') ? Department::active()->find((int) $this->option('department')) : null;

    if ($this->option('department') && $department === null) {
        $this->error('No active department with that id.');

        return 1;
    }

    $raw = (string) file_get_contents($this->argument('file') ?? 'php://stdin');
    [$outcome, $reason] = $inbound->handle($raw, $department);
    $this->info(ucfirst($outcome).": {$reason}.");

    // Ignored mail still exits 0 so the mail server doesn't bounce it back to the sender.
    return 0;
})->purpose('Read an email on standard input and add it to a ticket or open a new one');

Schedule::command('billing:run')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('activity:prune')->dailyAt('03:15');
