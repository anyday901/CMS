<?php

namespace App\Integrations\Chat;

/**
 * Builds the JSON each chat service's incoming webhook expects for a
 * one-line notification with a link.
 */
class ChatMessage
{
    /** @return array<string, mixed> */
    public static function for(string $type, string $text, ?string $url = null, ?string $linkText = 'Open'): array
    {
        $app = (string) config('app.name');

        return match ($type) {
            // Slack's mrkdwn: <url|text> links, and &, < and > escaped.
            'slack' => ['text' => self::slackEscape($text).($url ? " <{$url}|".self::slackEscape((string) $linkText).'>' : '')],
            // Discord shows Markdown links in embeds; mentions are switched off.
            'discord' => [
                'username' => $app,
                'content' => self::discordEscape($text).($url ? "\n<{$url}>" : ''),
                'allowed_mentions' => ['parse' => []],
            ],
            // Teams workflows ("Post to a channel when a webhook request is received") take an Adaptive Card.
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [['type' => 'TextBlock', 'text' => $text, 'wrap' => true]],
                        'actions' => $url ? [['type' => 'Action.OpenUrl', 'title' => (string) $linkText, 'url' => $url]] : [],
                    ],
                ]],
            ],
        };
    }

    private static function slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /** Stops @everyone and similar from pinging, and Markdown in names from formatting. */
    private static function discordEscape(string $text): string
    {
        return preg_replace('/([\\\\*_~`|>])/', '\\\\$1', str_replace('@', "@\u{200B}", $text));
    }
}
