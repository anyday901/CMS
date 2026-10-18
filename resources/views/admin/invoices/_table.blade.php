@php use App\Support\Money; $mergeable ??= false; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr>@if ($mergeable)<th class="w-8 px-4 py-2"><span class="sr-only">Merge</span></th>@endif<th class="px-4 py-2">#</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Issued</th><th class="px-4 py-2">Due</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2">Status</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($invoices as $invoice)
                <tr>
                    @if ($mergeable)
                        {{-- Ticked boxes belong to the merge form outside the table. --}}
                        <td class="px-4 py-2">@if ($invoice->status->value === 'unpaid' && ! $invoice->is_funds)<input type="checkbox" name="invoices[]" value="{{ $invoice->id }}" form="merge-invoices" aria-label="Merge invoice {{ $invoice->number }}">@endif</td>
                    @endif
                    <td class="px-4 py-2"><a href="{{ route('admin.invoices.show', $invoice) }}" class="text-brand-600">{{ $invoice->number }}</a></td>
                    <td class="px-4 py-2">{{ $invoice->client->fullName() }}</td>
                    <td class="px-4 py-2">{{ $invoice->issue_date->format('M j, Y') }}</td>
                    <td class="px-4 py-2">{{ $invoice->due_date->format('M j, Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td>
                    <td class="px-4 py-2">
                        <x-status-badge :status="$invoice->status->value === 'unpaid' && $invoice->due_date->lt(today()) ? 'overdue' : $invoice->status" />
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
