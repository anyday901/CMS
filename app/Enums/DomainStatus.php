<?php

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case PendingTransfer = 'pending_transfer';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case TransferredAway = 'transferred_away';
    case Fraud = 'fraud';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PendingTransfer => 'Transfer in progress',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::TransferredAway => 'Transferred away',
            self::Fraud => 'Fraud',
        };
    }

    /** Statuses the client still manages and renews. */
    public static function live(): array
    {
        return [self::Active, self::Expired];
    }

    /** Statuses the nightly sync asks the registrar about. */
    public static function synced(): array
    {
        return [self::Active, self::Expired, self::PendingTransfer];
    }
}
