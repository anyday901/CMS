<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A setting changed in the admin area. It overrides the config/.env value. */
class Setting extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
