<?php

namespace App\Addons\ProjectManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff member's running timer. Stopping it logs the time on its project. */
class Timer extends Model
{
    protected $table = 'pm_timers';

    protected $fillable = ['user_id', 'project_id', 'task_id', 'description', 'started_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Whole minutes so far, at least one. */
    public function minutes(): int
    {
        return max(1, (int) ceil($this->started_at->diffInSeconds(now()) / 60));
    }
}
