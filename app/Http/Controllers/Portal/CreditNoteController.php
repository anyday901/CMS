<?php

namespace App\Http\Controllers\Portal;

use App\Billing\CreditNotePdf;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The client's credit notes, listed on their invoices; this downloads one. */
class CreditNoteController extends Controller
{
    public function pdf(Request $request, int $creditNote, CreditNotePdf $pdf): Response
    {
        return $pdf->download($request->user('client')->creditNotes()->findOrFail($creditNote));
    }
}
