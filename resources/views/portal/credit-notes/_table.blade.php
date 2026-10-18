@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">{{ __('Credit note') }}</th><th class="px-4 py-2">{{ __('Date') }}</th>@unless ($hideInvoice ?? false)<th class="px-4 py-2">{{ __('Invoice') }}</th>@endunless<th class="px-4 py-2">{{ __('Reason') }}</th><th class="px-4 py-2">{{ __('Paid back') }}</th><th class="px-4 py-2 text-right">{{ __('Total') }}</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($creditNotes as $note)
                <tr>
                    <td class="whitespace-nowrap px-4 py-2"><a href="{{ route('portal.credit-notes.pdf', $note) }}" class="text-brand-600">{{ $note->number }}</a></td>
                    <td class="whitespace-nowrap px-4 py-2">{{ $note->created_at->translatedFormat('M j, Y') }}</td>
                    @unless ($hideInvoice ?? false)<td class="px-4 py-2"><a href="{{ route('portal.invoices.show', $note->invoice) }}" class="text-brand-600">#{{ $note->invoice->number }}</a></td>@endunless
                    <td class="px-4 py-2">{{ $note->reason }}</td>
                    <td class="px-4 py-2">{{ $note->refund_mode === 'credit' ? __('To account credit') : __('Refunded') }}</td>
                    <td class="px-4 py-2 text-right">{{ Money::format($note->total, $note->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
