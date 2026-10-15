<?php

namespace App\Jobs;

use App\Integrations\Chat\ChatMessage;
use App\Models\NotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Posts one notification to a chat channel; tried three times, a minute apart. The channel shows the last error. */
class SendChatMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $channelId, public string $text, public ?string $url = null)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $channel = NotificationChannel::find($this->channelId);

        if ($channel === null) {
            return;
        }

        $error = $this->post($channel);
        $channel->update($error === null ? ['last_sent_at' => now(), 'last_error' => null] : ['last_error' => $error]);

        if ($error !== null && $this->job && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
            $this->release(60);
        }
    }

    /** Null when it went through, otherwise why not. */
    public function post(NotificationChannel $channel): ?string
    {
        try {
            $response = Http::timeout(10)->asJson()->post((string) $channel->webhook_url, ChatMessage::for($channel->type, $this->text, $this->url));
        } catch (ConnectionException $e) {
            return Str::limit($e->getMessage(), 500);
        }

        return $response->successful() ? null : Str::limit("{$channel->typeLabel()} answered HTTP {$response->status()}: {$response->body()}", 500);
    }
}
