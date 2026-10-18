@props(['document'])
{{-- The reverse charge note on an invoice or quote in the admin area and the portal. --}}
@if ($document->reverse_charge)
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-ink-200 bg-white p-4 text-sm']) }}>
        <h2 class="mb-1 font-semibold">{{ __('Reverse charge') }}</h2>
        <p class="text-ink-600">{{ __('VAT to be accounted for by the customer (Article 196, Council Directive 2006/112/EC).') }}</p>
        @if (config('billing.company.tax_id'))<p class="text-ink-600">{{ __('Supplier VAT number: :number', ['number' => config('billing.company.tax_id')]) }}</p>@endif
        @if ($document->client_tax_id)<p class="text-ink-600">{{ __('Customer VAT number: :number', ['number' => $document->client_tax_id]) }}</p>@endif
    </div>
@endif
