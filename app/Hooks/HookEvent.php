<?php

namespace App\Hooks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One thing that happened, as webhooks, chat channels, SMS and plugins see
 * it: the event key (such as "invoice.paid"), its data, a sentence for
 * people and a link to it in the admin area.
 */
final class HookEvent
{
    public readonly string $id;

    public readonly CarbonImmutable $occurredAt;

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly string $key,
        public readonly array $data,
        public readonly string $summary,
        public readonly ?string $url = null,
        public readonly ?int $clientId = null,
    ) {
        $this->id = (string) Str::uuid();
        $this->occurredAt = CarbonImmutable::now();
    }

    /** The body webhooks receive. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->key,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'summary' => $this->summary,
            'url' => $this->url,
            'data' => $this->data,
        ];
    }
}
