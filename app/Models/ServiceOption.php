<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A configurable option chosen for a service. */
class ServiceOption extends Model
{
    protected $fillable = ['product_option_id', 'product_option_choice_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
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
