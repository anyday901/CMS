<?php

namespace App\Domains;

use Carbon\CarbonImmutable;

/** How a register, transfer or renew request went. */
final readonly class RegistrarResult
{
    /** @param  array<string, mixed>  $data  Values the module keeps on the domain, such as its order id. */
    private function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?CarbonImmutable $expiry = null,
        public bool $inProgress = false,
        public array $data = [],
    ) {}

    /** Done; $expiry is the new expiry date when the registrar says it. */
    public static function done(?CarbonImmutable $expiry = null, array $data = [], ?string $message = null): self
    {
        return new self(true, $message, $expiry, false, $data);
    }

    /** Accepted, but the registrar finishes it later, as with most transfers. */
    public static function inProgress(array $data = [], ?string $message = null): self
    {
        return new self(true, $message, null, true, $data);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
