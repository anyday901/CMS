<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/** A file on a ticket message, kept on the private local disk. */
class TicketAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ticket_message_id', 'path', 'name', 'mime', 'size'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    public function humanSize(): string
    {
        return Number::fileSize($this->size);
    }
}
