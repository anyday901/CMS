<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'product_id', 'order_id', 'parent_service_id', 'label', 'billing_cycle', 'recurring_amount',
        'promotion_id', 'recurring_discount', 'status', 'registration_date', 'next_due_date', 'custom_fields',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Set while a provisioning action runs: the same number for every run of
     * one action (including staff retries after a failure), different for
     * each new action. Modules use it to recognise a repeat. Not saved.
     */
    public ?int $provisioningOperation = null;

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => ServiceStatus::class,
            'recurring_amount' => 'integer',
            'recurring_discount' => 'integer',
            'registration_date' => 'date',
            'next_due_date' => 'date',
            'suspended_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'provisioning_data' => 'array',
            'terminated_at' => 'datetime',
            'custom_fields' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** The service an add-on was ordered for. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_service_id');
    }

    /** Add-ons ordered for this service. */
    public function addons(): HasMany
    {
        return $this->hasMany(self::class, 'parent_service_id');
    }

    public function upgrades(): HasMany
    {
        return $this->hasMany(ServiceUpgrade::class);
    }

    /** What the client pays each period: the recurring amount less any recurring discount. */
    public function netRecurring(): int
    {
        return max(0, $this->recurring_amount - $this->recurring_discount);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ServiceOption::class);
    }

    /** The chosen configurable options in one line, or '' when there are none. */
    public function optionSummary(): string
    {
        return $this->options
            ->loadMissing(['option', 'choice'])
            ->sortBy(fn (ServiceOption $chosen) => [$chosen->option->sort_order, $chosen->option->id])
            ->map->label()
            ->join(', ');
    }

    /**
     * Custom field values by field name, for modules and the webhook.
     *
     * @return array<string, string>
     */
    public function customFieldsByName(): array
    {
        return CustomField::byName(CustomField::forService($this->product_id)->get(), $this->custom_fields ?? []);
    }

    public function description(): string
    {
        return $this->label
            ? "{$this->product->name} - {$this->label}"
            : $this->product->name;
    }
}
