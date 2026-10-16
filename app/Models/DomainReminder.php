<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A renewal reminder or expiry notice already sent for a domain. */
class DomainReminder extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['domain_id', 'expiry_date', 'days'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'days' => 'integer'];
    }
}
