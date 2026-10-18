<?php

namespace App\Payments\Gateways;

class BankTransfer extends OfflineGateway
{
    public function key(): string
    {
        return 'bank_transfer';
    }

    public function label(): string
    {
        return 'Bank transfer';
    }

    protected function instructionsKey(): string
    {
        return 'payments.bank_transfer.instructions';
    }
}
