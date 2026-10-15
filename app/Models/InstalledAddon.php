<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An add-on module installed under Settings > Add-ons. */
class InstalledAddon extends Model
{
    protected $table = 'addons';

    protected $fillable = ['key', 'version', 'enabled', 'settings'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'encrypted:array',
        ];
    }
}
