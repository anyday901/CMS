<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One try at charging a saved payment method for an invoice. */
class PaymentAttempt extends Model
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** The gateway is still processing it; its webhook settles it. */
    public const PENDING = 'pending';

    /** Charged by the nightly billing run. */
    public const AUTO = 'auto';

    /** Charged by staff from the invoice page. */
    public const STAFF = 'staff';

    protected $fillable = [
        'invoice_id', 'client_id', 'payment_method_id', 'transaction_id', 'gateway_reference', 'method_label', 'gateway',
        'currency', 'amount', 'status', 'message', 'source', 'attempted_on',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'attempted_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
