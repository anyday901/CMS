<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * POSTs one event to a webhook, signed with its secret. An answer other
 * than 2xx is tried again after 1 minute, 5 minutes, 30 minutes and 2
 * hours; the delivery log keeps the last answer.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /** Waits before each retry, in seconds. */
    public const BACKOFF = [60, 300, 1800, 7200];

    public int $tries = 5;

    public function __construct(public int $deliveryId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('webhook')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === WebhookDelivery::DELIVERED || $delivery->webhook === null) {
            return;
        }

        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $delivery->increment('attempts');

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('app.name').' webhooks',
                'X-Webhook-Event' => $delivery->event,
                'X-Webhook-Delivery' => $delivery->event_id,
                'X-Webhook-Signature' => self::signature($body, (string) $delivery->webhook->secret),
            ])->timeout(15)->withBody($body, 'application/json')->post($delivery->webhook->url);

            $ok = $response->successful();
            $delivery->update([
                'status' => $ok ? WebhookDelivery::DELIVERED : WebhookDelivery::FAILED,
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 2000),
                'error' => $ok ? null : "The URL answered HTTP {$response->status()}.",
                'delivered_at' => $ok ? now() : null,
            ]);
        } catch (ConnectionException $e) {
            $ok = false;
            $delivery->update(['status' => WebhookDelivery::FAILED, 'response_status' => null, 'response_body' => null, 'error' => Str::limit($e->getMessage(), 1000)]);
        }

        if (! $ok && $this->job && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
            $this->release(self::BACKOFF[$this->attempts() - 1] ?? 7200);
        }
    }

    /** The X-Webhook-Signature header: an HMAC-SHA256 of the raw body with the webhook's secret. */
    public static function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }
}
