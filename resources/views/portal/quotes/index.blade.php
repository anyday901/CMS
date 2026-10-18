@php use App\Support\Money; @endphp
<x-portal-layout :title="__('Quotes')">
    <h1 class="mb-6 text-2xl font-semibold">{{ __('Quotes') }}</h1>
    @if ($quotes->isEmpty())
        <p class="text-sm text-ink-500">{{ __('You have no quotes.') }}</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500">
                    <tr><th class="px-4 py-2">{{ __('Quote') }}</th><th class="px-4 py-2">{{ __('Subject') }}</th><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2">{{ __('Valid until') }}</th><th class="px-4 py-2 text-right">{{ __('Total') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($quotes as $quote)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('portal.quotes.show', $quote) }}" class="text-brand-600">#{{ $quote->number }}</a></td>
                            <td class="px-4 py-2">{{ $quote->subject }}</td>
                            <td class="px-4 py-2">{{ $quote->issue_date->translatedFormat('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ $quote->valid_until->translatedFormat('M j, Y') }}</td>
                            <td class="px-4 py-2 text-right">{{ Money::format($quote->total, $quote->currency) }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$quote->awaitsClient() && $quote->isExpired() ? 'expired' : $quote->stage" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $quotes->links() }}</div>
    @endif
</x-portal-layout>
