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

/**
 * Whether invoices are numbered only once paid, and the format and next
 * number of paid invoices and of credit notes.
 */
class InvoiceNumberingController extends Controller
{
    private const NEXT = ['required', 'integer', 'min:1', 'max:999999999'];

    public function edit(): View
    {
        $format = (string) config('billing.invoice_number_format');
        $next = NumberSequence::peek(NumberSequence::INVOICE);
        $creditNoteFormat = (string) config('billing.credit_note_number_format');
        $creditNoteNext = NumberSequence::peek(NumberSequence::CREDIT_NOTE);

        return view('admin.invoice-numbering.edit', [
            'enabled' => InvoiceNumbering::enabled(),
            'format' => $format,
            'next' => $next,
            'example' => InvoiceNumbering::format($format, $next, now()),
            'creditNoteFormat' => $creditNoteFormat,
            'creditNoteNext' => $creditNoteNext,
            'creditNoteExample' => InvoiceNumbering::format($creditNoteFormat, $creditNoteNext, now()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sequential_paid_numbers' => ['boolean'],
            'invoice_number_format' => ['required', 'string', 'max:40'],
            'next_number' => self::NEXT,
            'credit_note_number_format' => ['required', 'string', 'max:40'],
            'credit_note_next_number' => self::NEXT,
        ]);

        $errors = array_filter([
            'invoice_number_format' => InvoiceNumbering::problem($data['invoice_number_format']),
            'credit_note_number_format' => str_contains($data['credit_note_number_format'], '{NUMBER}') ? null : 'The format needs {NUMBER}, the place the number goes.',
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $values = [
            'billing.sequential_paid_numbers' => $request->boolean('sequential_paid_numbers'),
            'billing.invoice_number_format' => $data['invoice_number_format'],
            'billing.credit_note_number_format' => $data['credit_note_number_format'],
        ];
        Settings::save($values);

        // Only when changed, so saving the page doesn't wind a sequence back
        // past numbers taken since it was opened.
        foreach ([NumberSequence::INVOICE => 'next_number', NumberSequence::CREDIT_NOTE => 'credit_note_next_number'] as $sequence => $field) {
            if ((int) $data[$field] !== $request->integer("shown_{$field}")) {
                NumberSequence::set($sequence, (int) $data[$field]);
            }
        }

        Activity::record('Updated invoice numbering settings', null, [
            'sequential_paid_numbers' => $values['billing.sequential_paid_numbers'],
            'invoice_number_format' => $values['billing.invoice_number_format'],
            'next_number' => NumberSequence::peek(NumberSequence::INVOICE),
            'credit_note_number_format' => $values['billing.credit_note_number_format'],
            'credit_note_next_number' => NumberSequence::peek(NumberSequence::CREDIT_NOTE),
        ]);

        return redirect()->route('admin.invoice-numbering.edit')->with('status', 'Invoice numbering saved.');
    }
}
