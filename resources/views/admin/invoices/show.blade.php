@php use App\Support\Money; @endphp
<x-admin-layout :title="'Invoice '.$invoice->number">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Invoice #{{ $invoice->number }}</h1>
        <x-status-badge :status="$invoice->status" />
        @if ($invoice->status->value === 'unpaid' && $invoice->amountPaid() === 0)
            <form method="POST" action="{{ route('admin.invoices.cancel', $invoice) }}" class="ml-auto" onsubmit="return confirm('Cancel this invoice?')">
                @csrf
                <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-100">Cancel invoice</button>
            </form>
        @endif
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2 space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm">
                <div class="grid grid-cols-2 gap-2">
                    <div><span class="text-gray-500">Client:</span> <a href="{{ route('admin.clients.show', $invoice->client) }}" class="text-indigo-600">{{ $invoice->client->fullName() }}</a></div>
                    <div><span class="text-gray-500">Issued:</span> {{ $invoice->issue_date->format('M j, Y') }}</div>
                    <div><span class="text-gray-500">Due:</span> {{ $invoice->due_date->format('M j, Y') }}</div>
                    @if ($invoice->paid_at)<div><span class="text-gray-500">Paid:</span> {{ $invoice->paid_at->format('M j, Y') }}</div>@endif
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Description</th><th class="px-4 py-2 text-right">Amount</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td class="px-4 py-2">
                                    @if ($item->service)<a href="{{ route('admin.services.show', $item->service) }}" class="text-indigo-600">{{ $item->description }}</a>@else{{ $item->description }}@endif
                                </td>
                                <td class="px-4 py-2 text-right">{{ Money::format($item->amount, $invoice->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="font-medium">
                        @if ($invoice->tax)<tr><td class="px-4 py-2 text-right">Tax</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->tax, $invoice->currency) }}</td></tr>@endif
                        <tr><td class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td></tr>
                        <tr><td class="px-4 py-2 text-right">Balance due</td><td class="px-4 py-2 text-right">{{ Money::format(max(0, $invoice->balance()), $invoice->currency) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            <div>
                <h2 class="mb-2 font-semibold">Payments</h2>
                @forelse ($invoice->transactions as $transaction)
                    <div class="text-sm">{{ $transaction->created_at->format('M j, Y') }} · {{ $transaction->methodLabel() }} · {{ Money::format($transaction->amount, $transaction->currency) }}@if ($transaction->fee) (fee {{ Money::format($transaction->fee, $transaction->currency) }})@endif @if ($transaction->gateway_reference) · {{ $transaction->gateway_reference }}@endif</div>
                @empty
                    <p class="text-sm text-gray-500">No payments yet.</p>
                @endforelse
            </div>
        </div>

        @if ($invoice->status->value === 'unpaid')
            <form method="POST" action="{{ route('admin.invoices.pay', $invoice) }}" class="h-fit space-y-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
                @csrf
                <h2 class="font-semibold">Record a payment</h2>
                <label class="block">Amount
                    <input name="amount" value="{{ old('amount', Money::toInput($invoice->balance())) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
                </label>
                <label class="block">Method
                    <select name="method" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
                        @foreach (['paypal' => 'PayPal', 'venmo' => 'Venmo', 'cashapp' => 'Cash App', 'cash' => 'Cash', 'check' => 'Check', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Reference (optional)
                    <input name="reference" value="{{ old('reference') }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
                </label>
                <button class="w-full rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Record payment</button>
            </form>
        @endif
    </div>
</x-admin-layout>
