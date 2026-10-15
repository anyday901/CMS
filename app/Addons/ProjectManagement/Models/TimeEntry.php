<?php

namespace App\Addons\ProjectManagement\Models;

use App\Addons\ProjectManagement\Duration;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time a staff member spent on a project. Billable time goes on an invoice
 * once; it counts as unbilled again if that invoice is cancelled or deleted,
 * or its line is removed.
 */
class TimeEntry extends Model
{
    protected $table = 'pm_time_entries';

    protected $fillable = ['task_id', 'user_id', 'worked_on', 'minutes', 'description', 'billable', 'rate'];

    protected $attributes = ['billable' => true, 'rate' => 0];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'minutes' => 'integer',
            'billable' => 'boolean',
            'rate' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    /**
     * Billable time not on a live invoice: never billed, or its invoice was cancelled.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnbilled(Builder $query): void
    {
        $query->where('billable', true)->where(fn ($query) => $query
            ->whereNull('invoice_item_id')
            ->orWhereHas('invoice', fn ($invoice) => $invoice->where('status', InvoiceStatus::Cancelled)));
    }

    /** On an invoice that hasn't been cancelled. */
    public function isBilled(): bool
    {
        return $this->invoice_item_id !== null && $this->invoice !== null && $this->invoice->status !== InvoiceStatus::Cancelled;
    }

    /** What this time is worth, or 0 when it isn't billable. */
    public function amount(): int
    {
        return $this->billable ? Duration::cost($this->minutes, $this->rate) : 0;
    }
}
