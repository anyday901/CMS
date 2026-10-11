<?php

namespace App\Enums;

enum StaffRole: string
{
    case Admin = 'admin';
    case Billing = 'billing';
    case Support = 'support';
    case Custom = 'custom';

    /** Abilities checked with Gate / the "can" middleware. */
    public const ABILITIES = ['manage-staff', 'manage-settings', 'manage-clients', 'manage-billing', 'manage-tickets'];

    /** What each ability lets staff do, for the staff form. */
    public static function abilityLabel(string $ability): string
    {
        return match ($ability) {
            'manage-staff' => 'Manage staff accounts',
            'manage-settings' => 'Change products, tax rules, settings, email templates and departments, and see the activity log',
            'manage-clients' => 'Add and edit clients and services, and run provisioning',
            'manage-billing' => 'Create and edit invoices, record payments, apply credit and refund',
            'manage-tickets' => 'Answer, assign and close support tickets',
            default => $ability,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Billing => 'Billing',
            self::Support => 'Support',
            self::Custom => 'Custom',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Everything, including staff, products, tax rules and the activity log.',
            self::Billing => 'Clients, services, invoices and support tickets: create, edit, payments, credit and refunds.',
            self::Support => 'Answers support tickets; read-only access to clients, services and invoices.',
            self::Custom => 'Only the permissions ticked below. Everyone can look at clients, services and invoices.',
        };
    }

    /** Abilities the role grants. A custom role grants none by itself; see User::allows(). */
    public function allows(string $ability): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Billing => in_array($ability, ['manage-clients', 'manage-billing', 'manage-tickets'], true),
            self::Support => $ability === 'manage-tickets',
            self::Custom => false,
        };
    }
}
