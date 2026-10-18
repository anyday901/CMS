@php use App\Billing\QuoteConverter; use App\Support\Money; @endphp
<x-portal-layout :title="__('Quote #:number', ['number' => $quote->number])">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ __('Quote #:number', ['number' => $quote->number]) }}</h1>
        <x-status-badge :status="$quote->awaitsClient() && $quote->isExpired() ? 'expired' : $quote->stage" />
        <a href="{{ route('portal.quotes.pdf', $quote) }}" class="ml-auto rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Download PDF') }}</a>
    </div>

    <div class="mb-6 grid gap-2 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div class="sm:col-span-3"><span class="text-ink-500">{{ __('Subject:') }}</span> {{ $quote->subject }}</div>
        <div><span class="text-ink-500">{{ __('Date:') }}</span> {{ $quote->issue_date->translatedFormat('M j, Y') }}</div>
        <div><span class="text-ink-500">{{ __('Valid until:') }}</span> {{ $quote->valid_until->translatedFormat('M j, Y') }}</div>
        @if ($quote->accepted_at)<div><span class="text-ink-500">{{ __('Accepted:') }}</span> {{ $quote->accepted_at->translatedFormat('M j, Y') }}</div>@endif
    </div>

    @if ($quote->notes)
        <div class="mb-6 rounded-lg border border-ink-200 bg-white p-4 text-sm">{!! nl2br(e($quote->notes)) !!}</div>
    @endif

    <div class="mb-6 overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">{{ __('Description') }}</th><th class="px-4 py-2 text-right">{{ __('Amount') }}</th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($quote->items as $item)
                    <tr><td class="px-4 py-2">{{ $item->description }}</td><td class="px-4 py-2 text-right">{{ Money::format($item->amount, $quote->currency) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot class="font-medium">
                @if ($quote->tax && ! $quote->tax_inclusive)<tr><td class="px-4 py-2 text-right">{{ __('Subtotal') }}</td><td class="px-4 py-2 text-right">{{ Money::format($quote->subtotal, $quote->currency) }}</td></tr>@endif
                @if ($quote->tax && ! $quote->tax_inclusive)<tr><td class="px-4 py-2 text-right">{{ $quote->taxLabel() }}</td><td class="px-4 py-2 text-right">{{ Money::format($quote->tax, $quote->currency) }}</td></tr>@endif
                <tr><td class="px-4 py-2 text-right">{{ __('Total') }}</td><td class="px-4 py-2 text-right">{{ Money::format($quote->total, $quote->currency) }}</td></tr>
                @if ($quote->tax && $quote->tax_inclusive)<tr><td class="px-4 py-2 text-right font-normal text-ink-500">{{ __('Includes :tax', ['tax' => $quote->taxLabel()]) }}</td><td class="px-4 py-2 text-right font-normal text-ink-500">{{ Money::format($quote->tax, $quote->currency) }}</td></tr>@endif
            </tfoot>
        </table>
    </div>

    @if ($quote->invoice)
        <p class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
            {{ __('You accepted this quote. It is now invoice #:number.', ['number' => $quote->invoice->number]) }}
            <a href="{{ route('portal.invoices.show', $quote->invoice) }}" class="font-medium text-brand-600">{{ $quote->invoice->status->value === 'unpaid' ? __('Pay the invoice') : __('View the invoice') }}</a>
        </p>
    @elseif ($problem = QuoteConverter::cannotAnswer($quote))
        <p class="rounded-md bg-ink-100 px-4 py-3 text-sm text-ink-700">{{ $problem }}</p>
    @else
        <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="mb-1 font-semibold">{{ __('Accept this quote?') }}</h2>
            <p class="mb-4 text-ink-600">{{ __('Accepting creates an invoice for :amount, which you can pay straight away.', ['amount' => Money::format($quote->total, $quote->currency)]) }}</p>
            <div class="flex flex-wrap items-start gap-3">
                <form method="POST" action="{{ route('portal.quotes.accept', $quote) }}" class="space-y-3">
                    @csrf
                    @if ($termsUrl)
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="accept_terms" value="1" required class="mt-0.5" @checked(old('accept_terms'))>
                            <span>{!! __('I have read and agree to the :terms.', ['terms' => '<a href="'.e($termsUrl).'" target="_blank" rel="noopener" class="text-brand-600 underline">'.e(__('terms of service')).'</a>']) !!}</span>
                        </label>
                    @endif
                    <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Accept quote') }}</button>
                </form>
                <form method="POST" action="{{ route('portal.quotes.decline', $quote) }}" onsubmit="return confirm(@js(__('Decline this quote?')))" class="{{ $termsUrl ? 'self-end' : '' }}">
                    @csrf
                    <button class="rounded-md px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Decline') }}</button>
                </form>
            </div>
        </div>
    @endif
</x-portal-layout>
