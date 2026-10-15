<?php

namespace App\Jobs;

use App\Mail\MassMailAudience;
use App\Mail\MassMailer;
use App\Models\Client;
use App\Models\MassMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues a mass mail's emails, one per client the filters pick at the time
 * it runs. A client already sent it (when the job is retried) is skipped.
 */
class SendMassMail implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $massMailId)
    {
        $this->afterCommit();
    }

    public function handle(MassMailer $mailer): void
    {
        $mail = MassMail::find($this->massMailId);

        if ($mail === null || $mail->status === MassMail::SENT) {
            return;
        }

        $mail->update(['status' => MassMail::SENDING]);

        MassMailAudience::query($mail->filters, $mail->marketing)
            ->whereDoesntHave('emailLogs', fn ($q) => $q->where('mass_mail_id', $mail->id))
            ->chunkById(200, fn ($clients) => $clients->each(fn (Client $client) => $mailer->queue($mail, $client)));

        $mail->update(['status' => MassMail::SENT, 'recipients' => $mail->emails()->count(), 'sent_at' => now()]);
    }
}
