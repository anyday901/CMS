<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * Sets up and manages a service somewhere else: a control panel, a VPS host,
 * your own API. Register the class in config/provisioning.php, then choose it
 * on a product. Each action runs on the queue after the billing change that
 * caused it is saved.
 *
 * Return ProvisioningResult::failed() for a problem staff should see and
 * retry; anything thrown is caught and shown the same way. An action can run
 * again when staff retry it, so make each one safe to repeat.
 */
interface ProvisioningModule
{
    /** Stored on products, e.g. "proxmox". Keep it stable. */
    public function key(): string;

    public function label(): string;

    /**
     * Settings shown on the product form and saved in the product's
     * module_config, keyed by field name. "type" is text (the default),
     * textarea or url.
     *
     * @return array<string, array{label: string, required?: bool, help?: string, type?: string}>
     */
    public function configFields(): array;

    /** Called when a service becomes active (first payment, or added without an invoice). */
    public function create(Service $service): ProvisioningResult;

    public function suspend(Service $service): ProvisioningResult;

    public function unsuspend(Service $service): ProvisioningResult;

    public function terminate(Service $service): ProvisioningResult;
}
