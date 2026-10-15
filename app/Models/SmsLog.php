<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One text message sent (or being sent) to staff or a client. */
class SmsLog extends Model
{
    use MassPrunable;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $table = 'sms_log';

    protected $fillable = ['client_id', 'event', 'provider', 'to', 'message', 'status', 'provider_reference', 'error', 'sent_at'];

    /** Texts are kept 90 days. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
