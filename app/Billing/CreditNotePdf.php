<?php

namespace App\Billing;

use App\Models\CreditNote;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as Document;
use Symfony\Component\HttpFoundation\Response;

/** Renders a credit note as a PDF, for staff and the client to download. */
class CreditNotePdf
{
    public function download(CreditNote $note): Response
    {
        return $this->render($note)->download($this->filename($note));
    }

    /** The PDF file's contents. */
    public function output(CreditNote $note): string
    {
        return $this->render($note)->output();
    }

    public function filename(CreditNote $note): string
    {
        return 'credit-note-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $note->number).'.pdf';
    }

    private function render(CreditNote $note): Document
    {
        $note->loadMissing(['client', 'items', 'invoice', 'refunds.refundOf']);

        return Pdf::loadView('pdf.credit-note', [
            'note' => $note,
            'company' => config('billing.company'),
        ])->setPaper('letter');
    }
}
