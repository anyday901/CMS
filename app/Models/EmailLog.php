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
        'whmcs_id', 'client_id', 'invoice_id', 'quote_id', 'ticket_id', 'mass_mail_id', 'template', 'to', 'cc', 'subject', 'body',
        'status', 'error', 'attach_invoice_pdf', 'reply_to', 'list_unsubscribe', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attach_invoice_pdf' => 'boolean',
            'cc' => 'array',
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

    /** The quote whose PDF goes with the email. */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function massMail(): BelongsTo
    {
        return $this->belongsTo(MassMail::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
