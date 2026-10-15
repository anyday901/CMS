<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group of clients, such as resellers or VIPs, shown as a coloured label and used to filter the clients list. */
class ClientGroup extends Model
{
    /** Colours staff can pick, as a hex code and a name. */
    public const COLOURS = [
        '#64748b' => 'Grey',
        '#0d9488' => 'Teal',
        '#2563eb' => 'Blue',
        '#7c3aed' => 'Purple',
        '#db2777' => 'Pink',
        '#dc2626' => 'Red',
        '#ea580c' => 'Orange',
        '#ca8a04' => 'Gold',
        '#16a34a' => 'Green',
    ];

    protected $fillable = ['name', 'colour', 'description'];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
