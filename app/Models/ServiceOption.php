<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A configurable option chosen for a service, with the unit price it's billed at. */
class ServiceOption extends Model
{
    protected $fillable = ['product_option_id', 'product_option_choice_id', 'quantity', 'unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function choice(): BelongsTo
    {
        return $this->belongsTo(ProductOptionChoice::class, 'product_option_choice_id');
    }

    /** "Disk: 50 GB", "Backups" or "Extra IPs: 2". */
    public function label(): string
    {
        return match ($this->option->type) {
            ProductOption::DROPDOWN => "{$this->option->name}: {$this->choice->name}",
            ProductOption::QUANTITY => "{$this->option->name}: {$this->quantity}",
            default => $this->option->name,
        };
    }
}
