<?php

namespace App\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/** Renders an invoice as a PDF for download. */
class InvoicePdf
{
    public function download(Invoice $invoice): Response
    {
        $invoice->loadMissing(['client', 'items', 'transactions']);

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
        ])->setPaper('letter')->download("invoice-{$invoice->number}.pdf");
    }
}
