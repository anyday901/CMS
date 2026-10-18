<?php

namespace App\Billing;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as Document;
use Symfony\Component\HttpFoundation\Response;

/** Renders a quote as a PDF, for download or to attach to the email that sends it. */
class QuotePdf
{
    public function download(Quote $quote): Response
    {
        return $this->render($quote)->download($this->filename($quote));
    }

    /** The PDF file's contents. */
    public function output(Quote $quote): string
    {
        return $this->render($quote)->output();
    }

    public function filename(Quote $quote): string
    {
        return "quote-{$quote->number}.pdf";
    }

    private function render(Quote $quote): Document
    {
        $quote->loadMissing(['client', 'items']);

        return Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'company' => config('billing.company'),
        ])->setPaper('letter');
    }
}
