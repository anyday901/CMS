<?php

namespace App\Jobs;

use App\Billing\InvoicePdf;
use App\Mail\LoggedEmail;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one logged email and records the outcome on the log. A failed send is
 * tried again twice, a few minutes apart; the log shows the last error.
 */
class SendLoggedEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  string|null  $body  The real body when the log only keeps a redacted copy. */
    public function __construct(public int $logId, public ?string $body = null)
    {
        $this->afterCommit();
    }

    public function handle(InvoicePdf $pdfs): void
    {
        $log = EmailLog::with(['invoice', 'massMail', 'client'])->find($this->logId);

        if ($log === null || $log->status === EmailLog::SENT) {
            return;
        }

        // A client who unsubscribed after a marketing email was queued doesn't get it.
        if ($log->massMail?->marketing && ! $log->client?->marketing_emails) {
            $log->update(['status' => EmailLog::FAILED, 'error' => 'Not sent: the client unsubscribed from marketing emails before it went out.']);

            return;
        }

        try {
            $pdf = $log->attach_invoice_pdf && $log->invoice
                ? ['data' => $pdfs->output($log->invoice), 'name' => $pdfs->filename($log->invoice)]
                : null;

            Mail::to($log->to)->cc($log->cc ?? [])->send(new LoggedEmail($log->subject, $this->body ?? $log->body, $log->reply_to, $pdf, $log->list_unsubscribe));
        } catch (Throwable $e) {
            $log->update(['status' => EmailLog::FAILED, 'error' => Str::limit($e->getMessage(), 1000)]);
            report($e);

            if ($this->job && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
                $this->release(300 * $this->attempts());
            }

            return;
        }

        $log->update(['status' => EmailLog::SENT, 'error' => null, 'sent_at' => now()]);
    }
}
