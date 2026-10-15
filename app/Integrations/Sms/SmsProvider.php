<?php

namespace App\Integrations\Sms;

/**
 * A text message service. Add your own by implementing this and listing
 * the class in config/sms.php; its credential fields appear under
 * Settings > SMS.
 */
interface SmsProvider
{
    /** A short unique key, such as "twilio". */
    public function key(): string;

    public function name(): string;

    /**
     * Credentials staff fill in, by name: label, and whether it is secret
     * (kept encrypted and not shown again).
     *
     * @return array<string, array{label: string, secret?: bool, help?: string}>
     */
    public function fields(): array;

    /**
     * Sends one message and returns the provider's id for it.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws SmsFailed when the provider refuses it or can't be reached
     */
    public function send(string $to, string $message, string $from, array $credentials): ?string;
}
