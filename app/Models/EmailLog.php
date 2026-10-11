<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email sent (or being sent) to a client. */
class EmailLog extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'client_id', 'invoice_id', 'ticket_id', 'template', 'to', 'subject', 'body',
        'status', 'error', 'attach_invoice_pdf', 'reply_to', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attach_invoice_pdf' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
