<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An email piped in for tickets, and what became of it: a new ticket, a reply, ignored, or caught as spam. */
class InboundEmail extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Days the log keeps an email. */
    public const KEEP_DAYS = 90;

    public const SPAM = 'spam';

    public const OUTCOMES = ['opened' => 'Opened a ticket', 'replied' => 'Replied', 'ignored' => 'Ignored', self::SPAM => 'Spam'];

    protected $fillable = ['from', 'subject', 'outcome', 'reason', 'ticket_id'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOMES[$this->outcome] ?? $this->outcome;
    }
}
