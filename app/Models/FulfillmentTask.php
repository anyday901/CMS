<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A set-up, suspend, unsuspend or terminate job for staff to do by hand, with a checklist. */
class FulfillmentTask extends Model
{
    protected $fillable = ['service_id', 'action', 'operation', 'checklist', 'notes'];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null;
    }

    public function title(): string
    {
        $verb = match ($this->action) {
            'create' => 'Set up',
            default => ucfirst($this->action),
        };

        return "{$verb} {$this->service->description()}";
    }

    public function remaining(): int
    {
        return collect($this->checklist)->where('done', false)->count();
    }
}
