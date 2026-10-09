@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr><th class="px-4 py-2">Invoice</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Due</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2">Status</th></tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($invoices as $invoice)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route('portal.invoices.show', $invoice) }}" class="text-indigo-600">#{{ $invoice->number }}</a></td>
                    <td class="px-4 py-2">{{ $invoice->issue_date->format('M j, Y') }}</td>
                    <td class="px-4 py-2">{{ $invoice->due_date->format('M j, Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td>
                    <td class="px-4 py-2"><x-status-badge :status="$invoice->status->value === 'unpaid' && $invoice->due_date->lt(today()) ? 'overdue' : $invoice->status" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
