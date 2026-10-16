<?php

namespace App\Domains;

use App\Models\Tld;

/** A domain registration or transfer in the cart, priced. */
final readonly class DomainLine
{
    /** @param  list<string>  $nameservers  Empty for the default ones. */
    public function __construct(
        public string $name,
        public Tld $tld,
        public string $type,
        public int $years,
        public bool $idProtection,
        public ?string $eppCode,
        public array $nameservers,
        public int $amount,
        public int $renewal,
    ) {}
}
