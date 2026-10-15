<?php

namespace App\Addons\ProjectManagement\Models;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A piece of work, for a client or internal, with tasks and the time staff spend on it. */
class Project extends Model
{
    public const PLANNED = 'planned';

    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PLANNED => 'Planned',
        self::ACTIVE => 'Active',
        self::ON_HOLD => 'On hold',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    protected $table = 'pm_projects';

    protected $fillable = ['title', 'client_id', 'user_id', 'description', 'status', 'due_on', 'hourly_rate'];

    protected $attributes = ['status' => self::ACTIVE, 'hourly_rate' => 0];

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if ($project->isDirty('status')) {
                $project->completed_at = $project->status === self::COMPLETED ? now() : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'hourly_rate' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** The staff member who owns the project. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position')->orderBy('id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'pm_project_invoice');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [self::COMPLETED, self::CANCELLED]);
    }

    /** Money on this project is in the client's currency. */
    public function currency(): string
    {
        return $this->client?->currency ?? config('billing.currency');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->lt(today());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** The colour the status badge takes. */
    public function badge(): string
    {
        return match ($this->status) {
            self::PLANNED => 'pending',
            self::COMPLETED => 'done',
            default => $this->status,
        };
    }
}
