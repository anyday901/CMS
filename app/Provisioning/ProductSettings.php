<?php

namespace App\Provisioning;

use App\Models\Product;
use App\Models\Service;

/** A product's module settings, as a module reads them for a service. */
final class ProductSettings
{
    public function __construct(private readonly Product $product) {}

    public static function of(Service $service): self
    {
        return new self($service->product);
    }

    /** A setting, trimmed, or null when it isn't set. */
    public function get(string $key): ?string
    {
        $value = trim((string) ($this->product->module_config[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /** A setting that must be set; the error names it as $label. */
    public function required(string $key, string $label): string
    {
        return $this->get($key)
            ?? throw new ModuleError("Set the {$label} on the product {$this->product->name} first.");
    }

    /** A setting that must be a whole number above zero. */
    public function wholeNumber(string $key, string $label): int
    {
        $value = $this->required($key, $label);

        return ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : throw new ModuleError("The {$label} on the product {$this->product->name} must be a whole number above zero.");
    }
}
