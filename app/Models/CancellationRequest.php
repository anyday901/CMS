<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * A request to cancel a service, straight away or when its paid period
 * ends. A pending one stops the service from being invoiced again.
 */
class CancellationRequest extends Model
{
    public const IMMEDIATE = 'immediate';

    public const END_OF_PERIOD = 'end_of_period';

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    /** The client or staff took it back before it was carried out. */
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = ['service_id', 'type', 'reason', 'status', 'cancel_on', 'requested_by', 'completed_at'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'cancel_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** Validation rules for a request from the portal or the admin area. */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::in([self::IMMEDIATE, self::END_OF_PERIOD])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function typeLabel(): string
    {
        return $this->type === self::IMMEDIATE ? 'Straight away' : 'End of billing period';
    }
}
