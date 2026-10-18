<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\OfflineGateway;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as Document;
use Symfony\Component\HttpFoundation\Response;

/** Renders an invoice as a PDF, for download or to attach to an email. */
class InvoicePdf
{
    public function download(Invoice $invoice): Response
    {
        return $this->render($invoice)->download($this->filename($invoice));
    }

    /** The PDF file's contents. */
    public function output(Invoice $invoice): string
    {
        return $this->render($invoice)->output();
    }

    public function filename(Invoice $invoice): string
    {
        return "invoice-{$invoice->number}.pdf";
    }

    private function render(Invoice $invoice): Document
    {
        $invoice->loadMissing(['client', 'items', 'transactions']);

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'offlineGateways' => app(GatewayRegistry::class)->enabled()->whereInstanceOf(OfflineGateway::class),
        ])->setPaper('letter');
    }
}
