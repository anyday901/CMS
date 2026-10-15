<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A knowledge base article or announcement in another language. */
class ContentTranslation extends Model
{
    protected $fillable = ['locale', 'title', 'body', 'tags'];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
