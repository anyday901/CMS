<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $invoices = $request->user('client')->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->latest('issue_date')->latest('id')
            ->paginate(20);

        return view('portal.invoices.index', compact('invoices'));
    }

    public function show(Request $request, int $invoice): View
    {
        $invoice = $request->user('client')->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->with(['items', 'transactions'])
            ->findOrFail($invoice);

        return view('portal.invoices.show', compact('invoice'));
    }
}
