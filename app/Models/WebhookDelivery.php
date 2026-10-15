<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event sent (or being sent) to a webhook, with the last answer. */
class WebhookDelivery extends Model
{
    use MassPrunable;

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $fillable = ['webhook_id', 'event_id', 'event', 'payload', 'status', 'attempts', 'response_status', 'response_body', 'error', 'delivered_at'];

    /** Deliveries are kept 30 days. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
