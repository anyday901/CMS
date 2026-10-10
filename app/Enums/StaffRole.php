<?php

namespace App\Enums;

enum StaffRole: string
{
    case Admin = 'admin';
    case Billing = 'billing';
    case Support = 'support';

    /** Abilities checked with Gate / the "can" middleware. */
    public const ABILITIES = ['manage-staff', 'manage-settings', 'manage-clients', 'manage-billing'];

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Billing => 'Billing',
            self::Support => 'Support',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Everything, including staff, products, tax rules and the activity log.',
            self::Billing => 'Clients, services and invoices: create, edit, payments, credit and refunds.',
            self::Support => 'Read-only access to clients, services and invoices.',
        };
    }

    public function allows(string $ability): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Billing => in_array($ability, ['manage-clients', 'manage-billing'], true),
            self::Support => false,
        };
    }
}
