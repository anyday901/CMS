@php use App\Support\Money; @endphp
<x-portal-layout :title="'Invoice #'.$invoice->number">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Invoice #{{ $invoice->number }}</h1>
        <x-status-badge :status="$invoice->status->value === 'unpaid' && $invoice->due_date->lt(today()) ? 'overdue' : $invoice->status" />
        <a href="{{ route('portal.invoices.pdf', $invoice) }}" class="ml-auto rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Download PDF</a>
    </div>

    <div class="mb-6 grid gap-2 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div><span class="text-ink-500">Date:</span> {{ $invoice->issue_date->format('M j, Y') }}</div>
        <div><span class="text-ink-500">Due:</span> {{ $invoice->due_date->format('M j, Y') }}</div>
        @if ($invoice->paid_at)<div><span class="text-ink-500">Paid:</span> {{ $invoice->paid_at->format('M j, Y') }}</div>@endif
    </div>

    <div class="mb-6 overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Description</th><th class="px-4 py-2 text-right">Amount</th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($invoice->items as $item)
                    <tr><td class="px-4 py-2">{{ $item->description }}</td><td class="px-4 py-2 text-right">{{ Money::format($item->amount, $invoice->currency) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot class="font-medium">
                @if ($invoice->tax && ! $invoice->tax_inclusive)<tr><td class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->subtotal, $invoice->currency) }}</td></tr>@endif
                        @if ($invoice->tax && ! $invoice->tax_inclusive)<tr><td class="px-4 py-2 text-right">{{ $invoice->taxLabel() }}</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->tax, $invoice->currency) }}</td></tr>@endif
                <tr><td class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td></tr>
                @if ($invoice->tax && $invoice->tax_inclusive)<tr><td class="px-4 py-2 text-right font-normal text-ink-500">Includes {{ $invoice->taxLabel() }}</td><td class="px-4 py-2 text-right font-normal text-ink-500">{{ Money::format($invoice->tax, $invoice->currency) }}</td></tr>@endif
                @if ($invoice->amountPaid())<tr><td class="px-4 py-2 text-right">Paid</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->amountPaid(), $invoice->currency) }}</td></tr>@endif
                @if ($invoice->status->value === 'unpaid')<tr><td class="px-4 py-2 text-right">Balance due</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->balance(), $invoice->currency) }}</td></tr>@endif
            </tfoot>
        </table>
    </div>

    @if ($invoice->status->value === 'unpaid')
        <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="mb-3 font-semibold">Pay {{ Money::format($invoice->balance(), $invoice->currency) }}</h2>
            @php $client = auth('client')->user(); @endphp
            @if ($client->credit_balance > 0 && $client->currency === $invoice->currency)
                <form method="POST" action="{{ route('portal.invoices.credit', $invoice) }}" class="mb-4">
                    @csrf
                    <button class="rounded-md px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-100">Use my {{ Money::format(min($client->credit_balance, $invoice->balance()), $invoice->currency) }} account credit</button>
                </form>
            @endif
            @forelse ($gateways as $gateway)
                <div class="mb-4 max-w-sm">
                    @include($gateway->view(), ['invoice' => $invoice] + $gateway->viewData($invoice))
                </div>
            @empty
                <p class="text-ink-600">Please pay using the details we've sent you and include invoice #{{ $invoice->number }} as the reference.</p>
            @endforelse
            <p id="payment-error" class="hidden rounded-md bg-red-50 px-3 py-2 text-red-800" role="alert"></p>
        </div>
    @endif

    @if ($invoice->transactions->isNotEmpty())
        <div class="mt-6 text-sm">
            <h2 class="mb-2 font-semibold">Payments</h2>
            @foreach ($invoice->transactions as $transaction)
                <div>{{ $transaction->created_at->format('M j, Y') }} · @if ($transaction->isRefund())Refund to {{ $transaction->methodLabel() }}@else{{ $transaction->methodLabel() }}@endif · {{ Money::format($transaction->amount, $transaction->currency) }}</div>
            @endforeach
        </div>
    @endif
</x-portal-layout>
