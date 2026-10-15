<?php

namespace App\Tickets;

use ZBateson\MailMimeParser\IMessage;

/**
 * Decides whether an inbound ticket email, or a contact form message, is
 * spam, from the rules under Settings: blocked senders and domains, blocked
 * words in the subject or message, and the verdict of a spam checker on the
 * mail server (SpamAssassin and Rspamd add headers saying so).
 */
class SpamFilter
{
    /** @return string|null why it's spam, or null when it isn't */
    public function check(string $from, string $subject, string $body, ?IMessage $message = null): ?string
    {
        return match (true) {
            $message !== null && (bool) config('tickets.spam.use_spam_headers') && self::markedAsSpam($message) => 'the mail server marked it as spam',
            ($sender = $this->blockedSender($from)) !== null => "the sender matches the blocked sender {$sender}",
            ($phrase = self::firstIn($subject, (array) config('tickets.spam.blocked_subjects'))) !== null => "the subject contains the blocked phrase \"{$phrase}\"",
            ($phrase = self::firstIn($body, (array) config('tickets.spam.blocked_phrases'))) !== null => "the message contains the blocked phrase \"{$phrase}\"",
            default => null,
        };
    }

    /** The blocked address or domain this sender matches, if any. "@example.com" covers its subdomains too. */
    public function blockedSender(string $from): ?string
    {
        $from = strtolower(trim($from));
        $domain = substr((string) strrchr($from, '@'), 1);

        foreach ((array) config('tickets.spam.blocked_senders') as $rule) {
            $rule = strtolower(trim((string) $rule));
            $ruleDomain = ltrim($rule, '@');

            $matches = match (true) {
                $rule === '' => false,
                str_starts_with($rule, '@') || ! str_contains($rule, '@') => $domain === $ruleDomain || str_ends_with($domain, '.'.$ruleDomain),
                default => $from === $rule,
            };

            if ($matches) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * One rule per line, blank lines and repeats dropped, for saving a list.
     *
     * @return list<string>
     */
    public static function lines(?string $text): array
    {
        return collect(preg_split('/\R/', (string) $text))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->unique(fn (string $line) => mb_strtolower($line))
            ->values()
            ->all();
    }

    private static function markedAsSpam(IMessage $message): bool
    {
        $flag = strtolower(trim((string) $message->getHeaderValue('X-Spam-Flag')));
        $status = strtolower(trim((string) $message->getHeaderValue('X-Spam-Status')));
        $rspamd = strtolower(trim((string) $message->getHeaderValue('X-Spam')));

        return $flag === 'yes' || str_starts_with($status, 'yes') || $rspamd === 'yes';
    }

    /** @param  array<mixed>  $phrases */
    private static function firstIn(string $text, array $phrases): ?string
    {
        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);

            if ($phrase !== '' && mb_stripos($text, $phrase) !== false) {
                return $phrase;
            }
        }

        return null;
    }
}
