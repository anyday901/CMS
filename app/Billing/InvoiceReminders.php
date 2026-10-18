<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Mail\ClientMailer;
use App\Models\EmailLog;
use App\Models\Invoice;
use Carbon\CarbonInterface;

/**
 * Overdue reminders, run nightly. An invoice gets a reminder when it passes
 * each number of days in billing.reminder_days, unless one was already sent
 * since then. So a missed night, or an invoice already long overdue when
 * reminders are turned on, gets one reminder instead of several.
 */
class InvoiceReminders
{
    public function __construct(private ClientMailer $mailer) {}

    public function run(CarbonInterface $today): int
    {
        $days = collect(config('billing.reminder_days', []))->filter(fn ($d) => $d > 0)->sort()->values();

        if ($days->isEmpty()) {
            return 0;
        }

        $sent = 0;

        // A mass payment's invoices get their own reminders.
        Invoice::where('status', InvoiceStatus::Unpaid)->withoutFunds()
            ->whereDate('due_date', '<=', $today->copy()->subDays($days->first()))
            ->with('client')
            ->chunkById(200, function ($invoices) use ($today, $days, &$sent) {
                foreach ($invoices as $invoice) {
                    $overdue = (int) $invoice->due_date->diffInDays($today);
                    $passed = $days->filter(fn (int $d) => $overdue >= $d)->last();
                    $already = EmailLog::where('template', 'invoice_overdue')
                        ->where('invoice_id', $invoice->id)
                        ->where('created_at', '>=', $invoice->due_date->copy()->addDays($passed)->startOfDay())
                        ->exists();

                    if (! $already && $this->mailer->send($invoice->client, 'invoice_overdue', ['days_overdue' => $overdue], $invoice)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
