@php use App\Support\Money; @endphp
<x-portal-layout :title="'Invoice #'.$invoice->number">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Invoice #{{ $invoice->number }}</h1>
        <x-status-badge :status="$invoice->status->value === 'unpaid' && $invoice->due_date->lt(today()) ? 'overdue' : $invoice->status" />
    </div>

    <div class="mb-6 grid gap-2 rounded-lg border border-gray-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div><span class="text-gray-500">Date:</span> {{ $invoice->issue_date->format('M j, Y') }}</div>
        <div><span class="text-gray-500">Due:</span> {{ $invoice->due_date->format('M j, Y') }}</div>
        @if ($invoice->paid_at)<div><span class="text-gray-500">Paid:</span> {{ $invoice->paid_at->format('M j, Y') }}</div>@endif
    </div>

    <div class="mb-6 overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Description</th><th class="px-4 py-2 text-right">Amount</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($invoice->items as $item)
                    <tr><td class="px-4 py-2">{{ $item->description }}</td><td class="px-4 py-2 text-right">{{ Money::format($item->amount, $invoice->currency) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot class="font-medium">
                @if ($invoice->tax)<tr><td class="px-4 py-2 text-right">Tax</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->tax, $invoice->currency) }}</td></tr>@endif
                <tr><td class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td></tr>
                @if ($invoice->amountPaid())<tr><td class="px-4 py-2 text-right">Paid</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->amountPaid(), $invoice->currency) }}</td></tr>@endif
                @if ($invoice->status->value === 'unpaid')<tr><td class="px-4 py-2 text-right">Balance due</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->balance(), $invoice->currency) }}</td></tr>@endif
            </tfoot>
        </table>
    </div>

    @if ($invoice->status->value === 'unpaid')
        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm">
            <h2 class="mb-1 font-semibold">How to pay</h2>
            <p class="text-gray-600">Online payment is coming soon. Until then, please pay using the details we've sent you and include invoice #{{ $invoice->number }} as the reference.</p>
        </div>
    @endif
</x-portal-layout>
