<?php

namespace App\Provisioning;

/** What a module reports back after an action. */
final readonly class ProvisioningResult
{
    /** @param  array<string, mixed>  $data  Saved on the service's provisioning_data, merged with what is there. */
    private function __construct(public bool $ok, public ?string $message, public array $data) {}

    public static function ok(?string $message = null, array $data = []): self
    {
        return new self(true, $message, $data);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message, []);
    }
}
