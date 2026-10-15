<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's rating, from 1 to 5, of the support on a closed ticket, with an optional comment. */
class TicketFeedback extends Model
{
    public const MAX_RATING = 5;

    public const LABELS = [1 => 'Very poor', 2 => 'Poor', 3 => 'OK', 4 => 'Good', 5 => 'Excellent'];

    protected $table = 'ticket_feedback';

    protected $fillable = ['ticket_id', 'client_id', 'rating', 'comment'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->rating] ?? (string) $this->rating;
    }
}
