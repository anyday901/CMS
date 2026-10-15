<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One of the built-in email templates in a language other than the main one. */
class EmailTemplateTranslation extends Model
{
    protected $fillable = ['template', 'locale', 'subject', 'body'];
}
