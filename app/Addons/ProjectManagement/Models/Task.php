<?php

namespace App\Addons\ProjectManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A step of a project, for a staff member, by a date. Its notes are for staff only. */
class Task extends Model
{
    public const TODO = 'todo';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    public const STATUSES = [
        self::TODO => 'To do',
        self::IN_PROGRESS => 'In progress',
        self::DONE => 'Done',
    ];

    protected $table = 'pm_tasks';

    protected $fillable = ['title', 'notes', 'user_id', 'status', 'due_on', 'position'];

    protected $attributes = ['status' => self::TODO, 'position' => 0];

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            if (! $task->position) {
                $task->position = (int) static::where('project_id', $task->project_id)->max('position') + 1;
            }
        });

        static::saving(function (Task $task) {
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === self::DONE ? now() : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
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
