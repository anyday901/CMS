<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One callback or webhook a payment gateway sent, and what became of it. Written only by GatewayLogger. */
class GatewayLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const ERROR = 'error';

    public const OUTCOMES = [self::ACCEPTED => 'Accepted', self::REJECTED => 'Rejected', self::ERROR => 'Error'];

    /** Names for the built-in gateways' keys; other gateways show their key. */
    public const GATEWAYS = ['paypal' => 'PayPal', 'cashapp' => 'Cash App (Square)', 'stripe' => 'Stripe'];

    protected $fillable = ['gateway', 'endpoint', 'event', 'outcome', 'response_status', 'message', 'payload', 'ip_address', 'client_id', 'invoice_id'];

    protected function casts(): array
    {
        return ['response_status' => 'integer'];
    }

    /** Kept as long as the activity log: forever, unless a retention is set under Settings. */
    public function prunable(): Builder
    {
        $days = config('activity.retention_days');

        return blank($days)
            ? static::whereRaw('1 = 0')
            : static::where('created_at', '<', now()->subDays((int) $days));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function gatewayLabel(): string
    {
        return self::GATEWAYS[$this->gateway] ?? ucfirst(str_replace('_', ' ', $this->gateway));
    }

    /** The payload, indented for reading. */
    public function prettyPayload(): string
    {
        $decoded = json_decode((string) $this->payload, true);

        return is_array($decoded) ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $this->payload;
    }
}
