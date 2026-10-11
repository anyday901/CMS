<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A staff edit of one of the built-in templates in App\Mail\EmailTemplates. */
class EmailTemplate extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'subject', 'body', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
