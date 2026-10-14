<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Staff-written Markdown for public pages: raw HTML is shown as text and unsafe links are dropped. */
class Markdown
{
    public static function html(?string $markdown): string
    {
        return Str::markdown((string) $markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** Plain text for a preview, such as an announcement on a list. */
    public static function excerpt(?string $markdown, int $length = 220): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(self::html($markdown)))), $length);
    }
}
