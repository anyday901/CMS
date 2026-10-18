<?php

namespace App\Payments\Gateways;

use App\Models\Invoice;
use App\Payments\Gateway;

/**
 * A way to pay outside the app, such as a bank transfer or a cheque by post.
 * The invoice shows the instructions staff wrote, and staff record the
 * payment by hand when it arrives.
 */
abstract class OfflineGateway implements Gateway
{
    /** The setting holding the instructions, e.g. "payments.bank_transfer.instructions". */
    abstract protected function instructionsKey(): string;

    public function isEnabled(): bool
    {
        return filled($this->instructions());
    }

    public function instructions(): ?string
    {
        return config($this->instructionsKey());
    }

    public function view(): string
    {
        return 'payments.offline';
    }

    public function viewData(Invoice $invoice): array
    {
        return ['label' => $this->label(), 'instructions' => $this->instructions()];
    }
}
