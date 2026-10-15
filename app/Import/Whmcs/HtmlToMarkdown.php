<?php

namespace App\Import\Whmcs;

/**
 * Knowledge base articles, announcements and network issues are HTML in
 * WHMCS; here they are Markdown. Headings, paragraphs, line breaks, lists,
 * bold, italics, links, images and code come over; other tags are dropped,
 * keeping their text.
 */
class HtmlToMarkdown
{
    public static function convert(string $html): string
    {
        // Some installs keep the HTML escaped once more.
        if (! str_contains($html, '<') && str_contains($html, '&lt;')) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $html);
        $text = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $text);
        $decode = fn (string $value) => html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $inline = fn (string $value) => trim((string) preg_replace('/\s+/', ' ', $decode($value)));

        // Code blocks are set aside, behind a private-use character, so the clean-up below leaves them as they are.
        $blocks = [];
        $text = (string) preg_replace_callback('#<pre\b[^>]*>(.*?)</pre>#is', function ($m) use (&$blocks, $decode) {
            $blocks[] = "```\n".trim($decode((string) preg_replace('#<br\s*/?>#i', "\n", $m[1])), "\n")."\n```";

            return "\n\n\u{E000}".(count($blocks) - 1)."\u{E000}\n\n";
        }, $text);
        $text = (string) preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', fn ($m) => "\n\n".str_repeat('#', (int) $m[1]).' '.$inline($m[2])."\n\n", $text);
        $text = (string) preg_replace_callback('#<ol\b[^>]*>(.*?)</ol>#is', fn ($m) => "\n\n".preg_replace('#<li\b[^>]*>#i', "\n1. ", $m[1])."\n\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', "\n- ", $text);
        $text = (string) preg_replace_callback('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) use ($inline) {
            $label = $inline($m[3]);
            $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return preg_match('#^(https?:|mailto:|/|\#)#i', $url) && $label !== '' ? "[{$label}]({$url})" : $label;
        }, $text);
        $text = (string) preg_replace_callback('#<img\b[^>]*src\s*=\s*(["\'])(.*?)\1[^>]*>#is', function ($m) {
            $alt = preg_match('#alt\s*=\s*(["\'])(.*?)\1#is', $m[0], $a) ? trim(html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return preg_match('#^(https?:|/)#i', $url) ? "![{$alt}]({$url})" : '';
        }, $text);
        $text = (string) preg_replace('#<(strong|b)\b[^>]*>(.*?)</\1>#is', '**$2**', $text);
        $text = (string) preg_replace('#<(em|i)\b[^>]*>(.*?)</\1>#is', '_$2_', $text);
        $text = (string) preg_replace('#<code\b[^>]*>(.*?)</code>#is', '`$1`', $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\\\n", $text);
        $text = (string) preg_replace('#</(p|div|ul|ol|table|blockquote)>#i', "\n\n", $text);
        $text = (string) preg_replace('#</(td|th)>#i', ' | ', $text);
        $text = str_ireplace('</tr>', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        // Indentation from the HTML source would turn lines into code blocks.
        $text = (string) preg_replace('/^[ \t]+(?!$)/m', '', $text);
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);

        $text = (string) preg_replace_callback('/\x{E000}(\d+)\x{E000}/u', fn ($m) => $blocks[(int) $m[1]], $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
