<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Staff wording for one portal string in one language (Settings > Portal text). */
class TranslationOverride extends Model
{
    protected $fillable = ['locale', 'key', 'value'];

    protected static function booted(): void
    {
        static::saving(fn (self $override) => $override->key_hash = self::hash($override->key));
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
