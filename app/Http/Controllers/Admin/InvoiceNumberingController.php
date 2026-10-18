<?php

namespace App\Http\Controllers\Admin;

use App\Billing\InvoiceNumbering;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\NumberSequence;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Whether invoices are numbered only once paid, the number format and the next number. */
class InvoiceNumberingController extends Controller
{
    public function edit(): View
    {
        $format = (string) config('billing.invoice_number_format');
        $next = NumberSequence::peek(NumberSequence::INVOICE);

        return view('admin.invoice-numbering.edit', [
            'enabled' => InvoiceNumbering::enabled(),
            'format' => $format,
            'next' => $next,
            'example' => InvoiceNumbering::format($format, $next, now()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sequential_paid_numbers' => ['boolean'],
            'invoice_number_format' => ['required', 'string', 'max:40'],
            'next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
        ]);

        if ($problem = InvoiceNumbering::problem($data['invoice_number_format'])) {
            throw ValidationException::withMessages(['invoice_number_format' => $problem]);
        }

        $values = [
            'billing.sequential_paid_numbers' => $request->boolean('sequential_paid_numbers'),
            'billing.invoice_number_format' => $data['invoice_number_format'],
        ];
        Settings::save($values);

        // Only when changed, so saving the page doesn't wind the sequence back
        // past numbers taken by payments since it was opened.
        if ((int) $data['next_number'] !== $request->integer('shown_next_number')) {
            NumberSequence::set(NumberSequence::INVOICE, (int) $data['next_number']);
        }

        Activity::record('Updated invoice numbering settings', null, [
            'sequential_paid_numbers' => $values['billing.sequential_paid_numbers'],
            'invoice_number_format' => $values['billing.invoice_number_format'],
            'next_number' => NumberSequence::peek(NumberSequence::INVOICE),
        ]);

        return redirect()->route('admin.invoice-numbering.edit')->with('status', 'Invoice numbering saved.');
    }
}
