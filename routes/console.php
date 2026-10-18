<?php

use App\Affiliates\Commissions;
use App\Billing\AutoCharger;
use App\Billing\InvoiceGenerator;
use App\Billing\InvoiceReminders;
use App\Billing\LateFeeProcessor;
use App\Billing\OverageBiller;
use App\Billing\OverdueProcessor;
use App\Billing\RefundReconciler;
use App\Billing\ServiceCanceller;
use App\Console\Scheduler;
use App\Domains\DomainReminders;
use App\Domains\DomainRenewals;
use App\Domains\DomainSync;
use App\Models\Activity;
use App\Models\Department;
use App\Monitoring\ServerMonitor;
use App\Provisioning\UsageCollector;
use App\Tickets\InactiveTicketCloser;
use App\Tickets\InboundMail;
use App\Tickets\TicketEscalator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

Artisan::command('billing:run', function (ServiceCanceller $cancellations, InvoiceGenerator $generator, OverageBiller $overages, AutoCharger $autoCharger, LateFeeProcessor $lateFees, OverdueProcessor $overdue, RefundReconciler $refunds, InvoiceReminders $reminders) {
    $cancelled = $cancellations->run(today());
    $this->info("Cancelled {$cancelled} service(s) at the end of their billing period.");

    $invoices = $generator->generate(today());
    $this->info("Generated {$invoices->count()} invoice(s).");

    $overageInvoices = $overages->run(today());
    $this->info("Generated {$overageInvoices->count()} overage invoice(s).");

    $attempts = $autoCharger->run(today());
    $this->info("Charged saved cards: {$attempts['succeeded']} paid, {$attempts['failed']} failed, {$attempts['pending']} processing.");

    $charged = $lateFees->run(today());
    $this->info("Added late fees to {$charged} invoice(s).");

    $counts = $overdue->run(today());
    $this->info("Suspended {$counts['suspended']} and terminated {$counts['terminated']} service(s).");

    $reminded = $reminders->run(today());
    $this->info("Sent {$reminded} overdue reminder(s).");

    $settled = $refunds->run();
    $this->info("Pending refunds: {$settled['completed']} completed, {$settled['failed']} failed.");
})->purpose('Carry out cancellation requests, generate renewal and overage invoices, charge saved cards, add late fees, send overdue reminders, process overdue services and check pending refunds');

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

Artisan::command('tickets:escalate', function (TicketEscalator $escalator) {
    $applied = $escalator->run(now());
    $this->info("Applied escalation rules {$applied} time(s).");
})->purpose('Apply the ticket escalation rules to the open tickets that match them');

Artisan::command('tickets:close-inactive', function (InactiveTicketCloser $closer) {
    if ((int) config('tickets.close_inactive_hours') <= 0) {
        $this->info('Closing inactive tickets is off; set the hours under Settings.');

        return;
    }

    $closed = $closer->run(now());
    $this->info("Closed {$closed} inactive ticket(s).");
})->purpose('Close answered tickets the client has not replied to within the hours set under Settings');

Artisan::command('usage:collect', function (UsageCollector $collector) {
    $result = $collector->collect(now());
    $this->info("Read the disk and bandwidth use of {$result['services']} service(s).");

    foreach ($result['errors'] as $error) {
        $this->warn("Couldn't read usage from {$error}");
    }
})->purpose('Read disk and bandwidth use from the servers whose modules report it');

Artisan::command('servers:check', function (ServerMonitor $monitor) {
    foreach ($monitor->checkAll() as $server) {
        $this->line("{$server->name}: {$server->statusLabel()}".($server->status_detail ? " ({$server->status_detail})" : ''));
    }
})->purpose('Check that each monitored server and its ports answer, and email staff when one goes down or comes back');

Artisan::command('affiliates:clear', function (Commissions $commissions) {
    $approved = $commissions->clear(now());
    $this->info("Approved {$approved} affiliate commission(s) past their clearing days.");
})->purpose('Approve pending affiliate commissions whose clearing days are up, so they count towards payouts');

Artisan::command('domains:run', function (DomainRenewals $renewals, DomainReminders $reminders) {
    $invoices = $renewals->generate(today());
    $this->info("Generated {$invoices->count()} domain renewal invoice(s).");

    $counts = $reminders->run(today());
    $this->info("Marked {$counts['expired']} domain(s) expired and sent {$counts['reminded']} renewal reminder(s).");
})->purpose('Generate renewal invoices for auto-renewing domains, send renewal reminders and mark lapsed domains expired');

Artisan::command('domains:sync {--limit= : Domains to sync this run; defaults to DOMAINS_SYNC_BATCH}', function (DomainSync $sync) {
    $counts = $sync->run($this->option('limit') !== null ? (int) $this->option('limit') : null);
    $this->info("Synced {$counts['synced']} domain(s) with their registrars; {$counts['failed']} failed.");
})->purpose('Update expiry dates and statuses of domains from their registrars, and finish transfers');

Scheduler::define(app(Schedule::class));
