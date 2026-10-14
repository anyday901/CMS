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
        $text = html_entity_decode(strip_tags(self::html($markdown)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text)), $length);
    }
}
