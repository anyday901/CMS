<?php

namespace App\Billing;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/** Renders a client's account statement as a PDF, for the portal's download. */
class StatementPdf
{
    public function download(AccountStatement $statement): Response
    {
        return Pdf::loadView('pdf.statement', [
            'statement' => $statement,
            'company' => config('billing.company'),
        ])->setPaper('letter')->download($this->filename($statement));
    }

    public function filename(AccountStatement $statement): string
    {
        return "statement-{$statement->from->toDateString()}-to-{$statement->to->toDateString()}.pdf";
    }
}
