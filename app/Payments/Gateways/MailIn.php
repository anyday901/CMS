<?php

namespace App\Payments\Gateways;

/** A cheque or money order sent by post, like WHMCS's "Mail In Payment". */
class MailIn extends OfflineGateway
{
    public function key(): string
    {
        return 'mail_in';
    }

    public function label(): string
    {
        return 'Mail-in payment';
    }

    protected function instructionsKey(): string
    {
        return 'payments.mail_in.instructions';
    }
}
