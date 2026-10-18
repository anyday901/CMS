<?php

namespace App\Http\Controllers\Portal;

use App\Billing\QuoteConverter;
use App\Billing\QuotePdf;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** The client's quotes: read them, download the PDF, and accept or decline. Drafts never show. */
class QuoteController extends Controller
{
    public function index(Request $request): View
    {
        $quotes = $request->user('client')->quotes()->visibleToClient()
            ->latest('issue_date')->latest('id')
            ->paginate(20);

        return view('portal.quotes.index', compact('quotes'));
    }

    public function show(Request $request, int $quote): View
    {
        $quote = $this->find($request, $quote)->load(['items', 'invoice']);

        return view('portal.quotes.show', [
            'quote' => $quote,
            'termsUrl' => config('store.terms_url') ?: null,
        ]);
    }

    public function pdf(Request $request, int $quote, QuotePdf $pdf): Response
    {
        return $pdf->download($this->find($request, $quote));
    }

    /** Accepting turns the quote into an invoice and sends the client to pay it. */
    public function accept(Request $request, int $quote, QuoteConverter $converter): RedirectResponse
    {
        $quote = $this->find($request, $quote);

        if (config('store.terms_url')) {
            $request->validate(['accept_terms' => ['accepted']], ['accept_terms.accepted' => __('Please agree to the terms of service to accept the quote.')]);
        }

        try {
            $invoice = $converter->accept($quote);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quote' => $e->getMessage()]);
        }

        return redirect()->route('portal.invoices.show', $invoice)
            ->with('status', __('Thank you for accepting quote #:number. Here is your invoice.', ['number' => $quote->number]));
    }

    public function decline(Request $request, int $quote, QuoteConverter $converter): RedirectResponse
    {
        $quote = $this->find($request, $quote);

        try {
            $converter->decline($quote);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quote' => $e->getMessage()]);
        }

        return redirect()->route('portal.quotes.index')
            ->with('status', __('You declined quote #:number. Thank you for letting us know.', ['number' => $quote->number]));
    }

    private function find(Request $request, int $id): Quote
    {
        return $request->user('client')->quotes()->visibleToClient()->findOrFail($id);
    }
}
