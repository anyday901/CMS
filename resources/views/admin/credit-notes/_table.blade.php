@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">Credit note</th><th class="px-4 py-2">Date</th>@unless ($hideInvoice ?? false)<th class="px-4 py-2">Invoice</th>@endunless<th class="px-4 py-2">Reason</th><th class="px-4 py-2">Paid back</th><th class="px-4 py-2 text-right">Total</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($creditNotes as $note)
                <tr>
                    <td class="whitespace-nowrap px-4 py-2"><a href="{{ route('admin.credit-notes.pdf', $note) }}" class="text-brand-600">{{ $note->number }}</a></td>
                    <td class="whitespace-nowrap px-4 py-2">{{ $note->created_at->format('M j, Y') }}</td>
                    @unless ($hideInvoice ?? false)<td class="px-4 py-2"><a href="{{ route('admin.invoices.show', $note->invoice) }}" class="text-brand-600">#{{ $note->invoice->number }}</a></td>@endunless
                    <td class="px-4 py-2">{{ $note->reason }}</td>
                    <td class="px-4 py-2 text-ink-500">{{ $note->modeLabel() }}@if ($note->user) by {{ $note->user->name }}@endif</td>
                    <td class="px-4 py-2 text-right">{{ Money::format($note->total, $note->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
