<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A file clients can download, such as software or a guide. It can be for
 * everyone, for logged-in clients, or for clients with an active service of
 * one of its products.
 */
class Download extends Model
{
    protected $fillable = ['download_category_id', 'title', 'description', 'path', 'file_name', 'mime', 'size', 'clients_only', 'sort_order'];

    protected function casts(): array
    {
        return ['clients_only' => 'boolean', 'size' => 'integer', 'downloads' => 'integer', 'sort_order' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DownloadCategory::class, 'download_category_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function needsLogin(): bool
    {
        return $this->clients_only || $this->products->isNotEmpty();
    }

    public function allows(?Client $client): bool
    {
        if (! $this->needsLogin()) {
            return true;
        }

        if ($client === null) {
            return false;
        }

        return $this->products->isEmpty()
            || $client->services()->where('status', ServiceStatus::Active)->whereIn('product_id', $this->products->modelKeys())->exists();
    }

    /** Who can download it, in words. */
    public function audience(): string
    {
        return match (true) {
            $this->products->isNotEmpty() => 'Clients with '.$this->products->pluck('name')->join(', ', ' or '),
            $this->clients_only => 'Logged-in clients',
            default => 'Everyone',
        };
    }
}
