<?php

namespace App\Enums;

/** Where a quote is in the sale, as in WHMCS. */
enum QuoteStage: string
{
    case Draft = 'draft';
    case Delivered = 'delivered';
    case OnHold = 'on_hold';
    case Accepted = 'accepted';
    case Lost = 'lost';
    case Dead = 'dead';

    /** Stages the client sees in the portal. Drafts, and quotes they declined or staff gave up on, stay hidden. */
    public const VISIBLE = ['delivered', 'on_hold', 'accepted'];

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Delivered => 'Delivered',
            self::OnHold => 'On hold',
            self::Accepted => 'Accepted',
            self::Lost => 'Lost',
            self::Dead => 'Dead',
        };
    }
}
