<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An item on the staff to-do list, optionally for a staff member, by a date, and about a client. */
class Todo extends Model
{
    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const STATUSES = [
        self::OPEN => 'To do',
        self::IN_PROGRESS => 'In progress',
        self::WAITING => 'Waiting',
        self::DONE => 'Done',
    ];

    protected $fillable = ['title', 'description', 'status', 'due_on', 'user_id', 'client_id'];

    protected $attributes = ['status' => self::OPEN];

    protected static function booted(): void
    {
        static::saving(function (Todo $todo) {
            if ($todo->isDirty('status')) {
                $todo->completed_at = $todo->status === self::DONE ? now() : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeUnfinished(Builder $query): void
    {
        $query->where('status', '!=', self::DONE);
    }

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', '!=', self::DONE)->whereDate('due_on', '<', today());
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_on !== null && $this->due_on->lt(today());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
