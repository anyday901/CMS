<?php

namespace App\Domains;

use Carbon\CarbonImmutable;

/** What a registrar says about a domain, for the sync and the domain pages. */
final readonly class DomainInfo
{
    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    /** Still on its way to us. */
    public const PENDING_TRANSFER = 'pending_transfer';

    /** No longer in our account: moved to another registrar, or deleted. */
    public const GONE = 'gone';

    /** @param  list<string>  $nameservers */
    public function __construct(
        public string $status,
        public ?CarbonImmutable $expiry = null,
        public array $nameservers = [],
        public ?bool $locked = null,
        public ?bool $idProtection = null,
    ) {}
}
