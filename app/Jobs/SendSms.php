<?php

namespace App\Jobs;

use App\Integrations\Sms\Sms;
use App\Integrations\Sms\SmsFailed;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Str;

/** Sends one logged text through the provider it was logged for; tried three times. */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $logId)
    {
        $this->afterCommit();
    }

    public function handle(Sms $sms): void
    {
        $log = SmsLog::find($this->logId);

        if ($log === null || $log->status === SmsLog::SENT) {
            return;
        }

        $provider = $sms->providers()->get($log->provider);

        try {
            if ($provider === null) {
                throw new SmsFailed("The {$log->provider} provider isn't available any more.");
            }
            $reference = $provider->send($log->to, $log->message, (string) config('sms.from'), $sms->credentials());
        } catch (SmsFailed $e) {
            $log->update(['status' => SmsLog::FAILED, 'error' => Str::limit($e->getMessage(), 1000)]);

            if ($provider !== null && $this->job && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
                $this->release(120 * $this->attempts());
            }

            return;
        }

        $log->update(['status' => SmsLog::SENT, 'provider_reference' => $reference, 'error' => null, 'sent_at' => now()]);
    }
}
