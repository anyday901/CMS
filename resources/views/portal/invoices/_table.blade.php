@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">{{ __('Invoice') }}</th><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2">{{ __('Due') }}</th><th class="px-4 py-2 text-right">{{ __('Total') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($invoices as $invoice)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route('portal.invoices.show', $invoice) }}" class="text-brand-600">#{{ $invoice->number }}</a>@if ($invoice->proforma) <span class="text-ink-500">{{ __('Proforma') }}</span>@endif</td>
                    <td class="px-4 py-2">{{ $invoice->issue_date->translatedFormat('M j, Y') }}</td>
                    <td class="px-4 py-2">{{ $invoice->due_date->translatedFormat('M j, Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td>
                    <td class="px-4 py-2"><x-status-badge :status="$invoice->status->value === 'unpaid' && $invoice->due_date->lt(today()) ? 'overdue' : $invoice->status" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
