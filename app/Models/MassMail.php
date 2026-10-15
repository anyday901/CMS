<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One email sent to many clients at once, with the filters that picked them. */
class MassMail extends Model
{
    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    protected $fillable = ['user_id', 'subject', 'body', 'marketing', 'filters', 'recipients', 'status', 'sent_at'];

    protected function casts(): array
    {
        return [
            'marketing' => 'boolean',
            'filters' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }
}
