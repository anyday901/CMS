<?php

namespace App\Billing;

/** What was picked for a new service beyond the product and cycle. */
readonly class OrderChoices
{
    /**
     * @param  array<int|string, mixed>  $options  Configurable option values by option id.
     * @param  array<string, string>|null  $customFields  Custom field values by field id.
     */
    public function __construct(
        public array $options = [],
        public ?array $customFields = null,
    ) {}
}
