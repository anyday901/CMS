<?php

namespace App\Domains;

/** What a WHOIS lookup found: whether the name is free, and the public record when it isn't. */
final readonly class WhoisResult
{
    /**
     * @param  bool|null  $available  Null when no lookup service answered.
     * @param  array<string, string>  $fields  Readable record fields, such as "Registrar" => "Example Inc.".
     * @param  string|null  $text  The raw reply of a port 43 WHOIS server.
     */
    public function __construct(
        public string $name,
        public ?bool $available,
        public array $fields = [],
        public ?string $text = null,
        public ?string $error = null,
    ) {}
}
