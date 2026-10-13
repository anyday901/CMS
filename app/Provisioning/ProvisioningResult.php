<?php

namespace App\Provisioning;

/** What a module reports back after an action. */
final readonly class ProvisioningResult
{
    /**
     * @param  array<string, mixed>  $data  Saved on the service's provisioning_data, merged with what is there.
     * @param  int|null  $retryAfter  Seconds to wait before running the action again, when it isn't finished yet.
     */
    private function __construct(public bool $ok, public ?string $message, public array $data, public ?int $retryAfter = null) {}

    public static function ok(?string $message = null, array $data = []): self
    {
        return new self(true, $message, $data);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message, []);
    }

    /**
     * The other system is still working, such as copying a disk: the same
     * action runs again after $seconds, and actions queued after it wait.
     * Save anything the next run needs on the service first.
     */
    public static function later(int $seconds, ?string $message = null): self
    {
        return new self(false, $message, [], max(1, $seconds));
    }
}
