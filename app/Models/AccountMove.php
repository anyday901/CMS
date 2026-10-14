<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service's account moving to another server, or to a server of another
 * module after an upgrade or downgrade. The service already points at the
 * new account; this keeps the old one so its module can remove it once
 * staff have copied the data over and finish the move.
 */
class AccountMove extends Model
{
    /** The new account is being created. */
    public const CREATING = 'creating';

    /** The new account is ready; staff copy the data (or check the module's copy), then finish the move. */
    public const COPYING = 'copying';

    /** The old account is being removed. */
    public const REMOVING = 'removing';

    public const DONE = 'done';

    /** Removing the old account failed; staff can try again. */
    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Moves still holding an old account, or waiting on the new one. */
    public const OPEN = [self::CREATING, self::COPYING, self::REMOVING, self::FAILED];

    /**
     * Added to the move's id for the old account's operation number, so a
     * module that remembers operations (manual tasks) never mistakes the
     * removal for one of the service's own actions.
     */
    private const OPERATION_BASE = 3_000_000_000;

    protected $fillable = [
        'service_id', 'status', 'from_product_id', 'to_product_id', 'from_server_id', 'to_server_id', 'from_module',
        'from_label', 'from_username', 'from_password', 'from_data', 'remove_old', 'remove_at_once', 'copy_data', 'error', 'requested_by', 'finished_at',
    ];

    protected $hidden = ['from_password'];

    protected function casts(): array
    {
        return [
            'from_password' => 'encrypted',
            'from_data' => 'array',
            'remove_old' => 'boolean',
            'remove_at_once' => 'boolean',
            'copy_data' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function fromServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'from_server_id');
    }

    public function toServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'to_server_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    /** Whether the move came with a new product, so it can't be undone by putting the old details back. */
    public function changesProduct(): bool
    {
        return $this->to_product_id !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::CREATING => $this->copy_data ? 'Copying the account to the new server' : 'Creating the new account',
            self::COPYING => $this->copy_data ? 'Copied, waiting for staff to check it and finish' : 'Waiting for the data to be copied',
            self::REMOVING => 'Removing the old account',
            self::DONE => 'Finished',
            self::FAILED => 'Removing the old account failed',
            default => 'Cancelled',
        };
    }

    /**
     * The old account as a service its module can act on: the service with
     * the old product, server, login and saved data. Never saved, so a
     * module can't overwrite the real service with it.
     */
    public function oldAccount(): Service
    {
        $service = $this->service()->with('client')->firstOrFail();
        $old = $service->replicate()->forceFill([
            'product_id' => $this->from_product_id,
            'server_id' => $this->from_server_id,
            'label' => $this->from_label,
            'username' => $this->from_username,
            'password' => $this->from_password,
            'new_password' => null,
            'provisioning_data' => $this->from_data,
        ]);
        $old->id = $service->id;
        $old->provisioningOperation = self::OPERATION_BASE + $this->id;
        $old->setRelation('client', $service->client);
        $old->setRelation('product', $this->fromProduct ?? $service->product);
        $old->setRelation('server', $this->fromServer);

        return $old;
    }
}
