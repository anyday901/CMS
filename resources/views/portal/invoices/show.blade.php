@php use App\Support\Money; @endphp
@php $title = $invoice->proforma ? __('Proforma #:number', ['number' => $invoice->number]) : __('Invoice #:number', ['number' => $invoice->number]); @endphp
<x-portal-layout :title="$title">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $title }}</h1>
        <x-status-badge :status="$invoice->status->value === 'unpaid' && $invoice->due_date->lt(today()) ? 'overdue' : $invoice->status" />
        <a href="{{ route('portal.invoices.pdf', $invoice) }}" class="ml-auto rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Download PDF') }}</a>
    </div>

    <div class="mb-6 grid gap-2 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div><span class="text-ink-500">{{ __('Date:') }}</span> {{ $invoice->issue_date->translatedFormat('M j, Y') }}</div>
        <div><span class="text-ink-500">{{ __('Due:') }}</span> {{ $invoice->due_date->translatedFormat('M j, Y') }}</div>
        @if ($invoice->paid_at)<div><span class="text-ink-500">{{ __('Paid:') }}</span> {{ $invoice->paid_at->translatedFormat('M j, Y') }}</div>@endif
        @if ($invoice->client_tax_id)<div><span class="text-ink-500">{{ __('Tax ID:') }}</span> {{ $invoice->client_tax_id }}</div>@endif
    </div>

    <div class="mb-6 overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">{{ __('Description') }}</th><th class="px-4 py-2 text-right">{{ __('Amount') }}</th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($invoice->items as $item)
                    <tr><td class="px-4 py-2">{{ $item->description }}</td><td class="px-4 py-2 text-right">{{ Money::format($item->amount, $invoice->currency) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot class="font-medium">
                @if ($invoice->tax && ! $invoice->tax_inclusive)<tr><td class="px-4 py-2 text-right">{{ __('Subtotal') }}</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->subtotal, $invoice->currency) }}</td></tr>@endif
                        @if ($invoice->tax && ! $invoice->tax_inclusive)@foreach ($invoice->taxLines() as [$taxLabel, $taxAmount])<tr><td class="px-4 py-2 text-right">{{ $taxLabel }}</td><td class="px-4 py-2 text-right">{{ Money::format($taxAmount, $invoice->currency) }}</td></tr>@endforeach @endif
                <tr><td class="px-4 py-2 text-right">{{ __('Total') }}</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td></tr>
                @if ($invoice->tax && $invoice->tax_inclusive)@foreach ($invoice->taxLines() as [$taxLabel, $taxAmount])<tr><td class="px-4 py-2 text-right font-normal text-ink-500">{{ __('Includes :tax', ['tax' => $taxLabel]) }}</td><td class="px-4 py-2 text-right font-normal text-ink-500">{{ Money::format($taxAmount, $invoice->currency) }}</td></tr>@endforeach @endif
                @if ($invoice->creditNotes->isNotEmpty())<tr><td class="px-4 py-2 text-right">{{ __('Credited') }}</td><td class="px-4 py-2 text-right">{{ Money::format(-$invoice->creditNotes->sum('total'), $invoice->currency) }}</td></tr>@endif
                @if ($invoice->amountPaid())<tr><td class="px-4 py-2 text-right">{{ __('Paid') }}</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->amountPaid(), $invoice->currency) }}</td></tr>@endif
                @if ($invoice->status->value === 'unpaid')<tr><td class="px-4 py-2 text-right">{{ __('Balance due') }}</td><td class="px-4 py-2 text-right">{{ Money::format($invoice->balance(), $invoice->currency) }}</td></tr>@endif
            </tfoot>
        </table>
    </div>

    <x-reverse-charge :document="$invoice" class="mb-6" />

    @if ($invoice->status->value === 'unpaid')
        <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="mb-3 font-semibold">{{ __('Pay :amount', ['amount' => Money::format($invoice->balance(), $invoice->currency)]) }}</h2>
            @php $client = auth('client')->user(); @endphp
            @if ($autoChargeCard)
                <p class="mb-4 text-ink-600">{{ __(':method will be charged automatically when this invoice is due, unless you pay it first.', ['method' => $autoChargeCard->label()]) }} <a href="{{ route('portal.payment-methods.index') }}" class="text-brand-600">{{ __('Payment methods') }}</a></p>
            @endif
            @if ($addingFunds)
                <p class="mb-4 text-ink-600">{{ __('Once this invoice is paid, the amount is added to your account credit.') }}</p>
            @endif
            @if ($massPayment)
                <p class="mb-4 text-ink-600">{!! __('This invoice is also on :invoice, which pays it together with others. Pay either one.', ['invoice' => '<a href="'.e(route('portal.invoices.show', $massPayment)).'" class="text-brand-600">'.e(__('invoice #:number', ['number' => $massPayment->number])).'</a>']) !!}</p>
            @endif
            @if ($client->credit_balance > 0 && $client->currency === $invoice->currency && ! $addingFunds)
                <form method="POST" action="{{ route('portal.invoices.credit', $invoice) }}" class="mb-4">
                    @csrf
                    <button class="rounded-md px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Use my :amount account credit', ['amount' => Money::format(min($client->credit_balance, $invoice->balance()), $invoice->currency)]) }}</button>
                </form>
            @endif
            @forelse ($gateways as $gateway)
                <div class="mb-4 max-w-sm">
                    @include($gateway->view(), ['invoice' => $invoice] + $gateway->viewData($invoice))
                </div>
            @empty
                <p class="text-ink-600">{{ __("Please pay using the details we've sent you and include invoice #:number as the reference.", ['number' => $invoice->number]) }}</p>
            @endforelse
            <p id="payment-error" class="hidden rounded-md bg-red-50 px-3 py-2 text-red-800" role="alert"></p>
        </div>
    @endif

    @if ($invoice->transactions->isNotEmpty())
        <div class="mt-6 text-sm">
            <h2 class="mb-2 font-semibold">{{ __('Payments') }}</h2>
            @foreach ($invoice->transactions as $transaction)
                <div>{{ $transaction->created_at->translatedFormat('M j, Y') }} · @if ($transaction->isRefund()){{ __('Refund to :method', ['method' => __($transaction->methodLabel())]) }}@else{{ __($transaction->methodLabel()) }}@endif · {{ Money::format($transaction->amount, $transaction->currency) }}</div>
            @endforeach
        </div>
    @endif

    @if ($invoice->creditNotes->isNotEmpty())
        <div class="mt-6 text-sm">
            <h2 class="mb-2 font-semibold">{{ __('Credit notes') }}</h2>
            @include('portal.credit-notes._table', ['creditNotes' => $invoice->creditNotes, 'hideInvoice' => true])
        </div>
    @endif
</x-portal-layout>
