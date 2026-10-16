@php use App\Support\Money; @endphp
<x-portal-layout :title="__('Domains')" :store="true">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('Find a domain') }}</h1>
    <p class="mb-6 text-sm text-ink-600">{{ __('Search for a new domain to register, or one you own to transfer to us.') }}</p>

    <form method="GET" action="{{ route('domains.index') }}" role="search" class="mb-8 flex max-w-2xl flex-wrap gap-2 text-sm">
        <label for="domain-search" class="sr-only">{{ __('Domain name') }}</label>
        <input id="domain-search" name="domain" value="{{ $search }}" required maxlength="253" placeholder="example.com" class="min-w-0 grow rounded-full bg-white px-4 py-2.5 ring-1 ring-ink-300">
        <input type="hidden" name="type" value="{{ $transfer ? 'transfer' : 'register' }}">
        <button class="rounded-full bg-brand-600 px-5 py-2.5 font-medium text-white hover:bg-brand-500">{{ $transfer ? __('Check transfer') : __('Search') }}</button>
        <p class="w-full text-ink-500">
            @if ($transfer)
                {!! __('Want a new domain instead? :link', ['link' => '<a href="'.e(route('domains.index', ['domain' => $search ?: null])).'" class="text-brand-600">'.e(__('Search to register')).'</a>']) !!}
            @else
                {!! __('Already own a domain? :link', ['link' => '<a href="'.e(route('domains.index', ['domain' => $search ?: null, 'type' => 'transfer'])).'" class="text-brand-600">'.e(__('Transfer it to us')).'</a>']) !!}
            @endif
            · <a href="{{ route('domains.whois', ['domain' => $search ?: null]) }}" class="text-brand-600">{{ __('WHOIS lookup') }}</a>
        </p>
    </form>

    @error('name')<p class="mb-4 max-w-2xl rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>@enderror

    @if ($lookup)
        @if ($lookup['error'])
            <p class="mb-8 max-w-2xl rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">{{ __($lookup['error']) }}</p>
        @else
            <div class="mb-10 divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                @foreach ($lookup['results'] as $i => $result)
                    @php
                        $tld = $result['tld'];
                        // A free name is registered; a taken one can be transferred if it's yours.
                        $type = $result['available'] === false || ($transfer && $i === 0) ? 'transfer' : 'register';
                        $terms = $tld->termsFor($currency, $type);
                    @endphp
                    <form method="POST" action="{{ route('domains.cart') }}" class="flex flex-wrap items-center gap-3 px-4 py-3 {{ $i === 0 ? 'bg-brand-50/40' : '' }}">
                        @csrf
                        <input type="hidden" name="name" value="{{ $result['name'] }}">
                        <input type="hidden" name="type" value="{{ $type }}">
                        <div class="min-w-48 grow">
                            <div class="font-medium {{ $i === 0 ? 'text-base' : '' }}">{{ $result['name'] }}</div>
                            @if ($result['available'] === true)
                                <div class="text-brand-700">{{ $type === 'transfer' ? __("Not registered yet, so it can't be transferred") : __('Available') }}</div>
                            @elseif ($result['available'] === false)
                                <div class="text-ink-500">{{ __('Taken. Transfer it if it is yours.') }}</div>
                            @else
                                <div class="text-ink-500">{{ __("We couldn't check this one. You can still try.") }}</div>
                            @endif
                        </div>
                        @if ($terms->isEmpty() || ($type === 'transfer' && $result['available'] === true))
                            <span class="text-ink-500">{{ $terms->isEmpty() ? __('Not sold') : '' }}</span>
                        @else
                            <label class="sr-only" for="years-{{ $i }}">{{ __('Years') }}</label>
                            <select id="years-{{ $i }}" name="years" class="rounded-md bg-white px-2 py-1.5 ring-1 ring-ink-300">
                                @foreach ($terms as $term)
                                    <option value="{{ $term->years }}">{{ trans_choice(':count year|:count years', $term->years) }}: {{ Money::format($term->{$type}, $currency) }}</option>
                                @endforeach
                            </select>
                            @if ($type === 'transfer' && $tld->epp_required)
                                <label class="sr-only" for="epp-{{ $i }}">{{ __('EPP code') }}</label>
                                <input id="epp-{{ $i }}" name="epp_code" required maxlength="100" placeholder="{{ __('EPP code') }}" autocomplete="off" class="w-36 rounded-md px-2 py-1.5 ring-1 ring-ink-300">
                            @endif
                            @if ($tld->id_protection)
                                <label class="flex items-center gap-1.5 text-ink-600"><input type="checkbox" name="id_protection" value="1"> {{ ($terms->first()->id_protection ?? 0) > 0 ? __('ID protection (+:amount)', ['amount' => Money::format($terms->first()->id_protection, $currency)]) : __('Free ID protection') }}</label>
                            @endif
                            <button class="rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">{{ $type === 'transfer' ? __('Transfer') : __('Add to cart') }}</button>
                        @endif
                    </form>
                @endforeach
            </div>
        @endif
    @endif

    @if ($tlds->isNotEmpty())
        <h2 class="mb-3 text-lg font-semibold">{{ __('Prices') }}</h2>
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">{{ __('Extension') }}</th><th class="px-4 py-2 text-right">{{ __('Register') }}</th><th class="px-4 py-2 text-right">{{ __('Transfer') }}</th><th class="px-4 py-2 text-right">{{ __('Renew') }}</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($tlds as $tld)
                        <tr>
                            <td class="px-4 py-2 font-medium">{{ $tld->label() }}</td>
                            @foreach (['register', 'transfer', 'renew'] as $type)
                                @php $price = $tld->fromPrice($currency, $type); @endphp
                                <td class="px-4 py-2 text-right">@if ($price){{ Money::format($price->{$type}, $currency) }} <span class="text-ink-500">{{ $price->years === 1 ? __('a year') : trans_choice(':count year|:count years', $price->years) }}</span>@else<span class="text-ink-400">—</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-500">{{ __('Before tax. Transfers usually add a year to the domain.') }}</p>
    @endif
</x-portal-layout>
