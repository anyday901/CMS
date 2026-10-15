@php use App\Support\Money; $currency = $service->client->currency; @endphp
<x-portal-layout :title="__('Move to :product', ['product' => $product->name])">
    <p class="mb-2 text-sm"><a href="{{ route('portal.services.upgrade', $service) }}" class="text-brand-600">{{ __('Upgrade or downgrade') }}</a></p>
    <h1 class="mb-6 text-2xl font-semibold">{{ __('Move :service to :product', ['service' => $service->description(), 'product' => $product->name]) }}</h1>

    @if ($product->options->isNotEmpty())
        <form method="GET" action="{{ route('portal.services.upgrade.show', [$service, $product]) }}" class="mb-6 max-w-xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <h2 class="font-semibold">{{ __('Options') }}</h2>
            @include('services._options', ['options' => $product->options, 'values' => $values, 'cycleHint' => $service->billing_cycle])
            <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">{{ $quote ? __('Update price') : __('See the price') }}</button>
        </form>
    @endif

    @if ($quote)
        <div class="max-w-xl rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <dl class="space-y-2">
                <div class="flex gap-3"><dt class="text-ink-500">{{ __('New price') }}</dt><dd class="ml-auto">{{ __(':price :cycle, from :date', ['price' => Money::format($quote->newNetRecurring(), $currency), 'cycle' => Str::lower(__($service->billing_cycle->label())), 'date' => $service->next_due_date->translatedFormat('M j, Y')]) }}</dd></div>
                <div class="flex gap-3"><dt class="text-ink-500">{{ __(':product for the :days left', ['product' => $product->name, 'days' => trans_choice(':count day|:count days', $quote->proration->daysLeft)]) }}</dt><dd class="ml-auto">{{ Money::format($quote->proration->charge, $currency) }}</dd></div>
                <div class="flex gap-3"><dt class="text-ink-500">{{ __('Unused time on :product', ['product' => $service->product->name]) }}</dt><dd class="ml-auto">-{{ Money::format($quote->proration->credit, $currency) }}</dd></div>
                <div class="flex gap-3 border-t border-ink-100 pt-2 font-semibold">
                    @if ($quote->net() > 0)
                        <dt>{{ __('To pay now (before tax)') }}</dt><dd class="ml-auto">{{ Money::format($quote->net(), $currency) }}</dd>
                    @else
                        <dt>{{ __('Added to your credit (plus any tax you paid on it)') }}</dt><dd class="ml-auto">{{ Money::format(-$quote->net(), $currency) }}</dd>
                    @endif
                </div>
            </dl>
            <form method="POST" action="{{ route('portal.services.upgrade.store', [$service, $product]) }}" class="mt-6">@csrf
                @foreach ($values as $optionId => $value)
                    @if (is_scalar($value))<input type="hidden" name="options[{{ $optionId }}]" value="{{ $value }}">@endif
                @endforeach
                <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">{{ $quote->net() > 0 ? __('Continue to payment') : __('Move now') }}</button>
                <p class="mt-2 text-ink-500">{{ $quote->net() > 0 ? __('The move happens once the invoice is paid.') : __('The move happens straight away.') }}</p>
                @if ($migrates)
                    <p class="mt-2 text-ink-500">{{ __(":product runs on a different kind of server, so you get a new account there. Your current account keeps working until we've copied your data over.", ['product' => $product->name]) }}</p>
                @endif
            </form>
        </div>
    @endif
</x-portal-layout>
