@php use App\Models\ProductOption; use App\Support\Money; @endphp
<x-admin-layout :title="'Edit '.$option->name">
    <h1 class="mb-2 text-2xl font-semibold">{{ $option->name }} <span class="text-base font-normal text-ink-500">{{ ProductOption::TYPES[$option->type] }} on <a href="{{ route('admin.product-options.index', $option->product) }}" class="text-brand-600">{{ $option->product->name }}</a></span></h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">
        @if ($option->type === ProductOption::QUANTITY)
            Prices are per unit. Leave a price blank to make it free on that billing cycle.
        @elseif ($option->type === ProductOption::CHECKBOX)
            The price is added when the box is ticked. Leave a price blank to make it free on that billing cycle.
        @else
            The first choice is the default. Leave a price blank to make that choice free on that billing cycle. Fill in a name on an empty row to add a choice.
        @endif
        Prices are in {{ config('billing.currency') }}.
        @if ($otherCurrencies)
            Below them are the prices for clients billed in other currencies. A choice priced in {{ config('billing.currency') }} can't be ordered in a currency where its row is blank; enter 0 to make it free there. <a href="{{ route('admin.currencies.index') }}" class="text-brand-600">Update product prices</a> converts them all at once.
        @endif
    </p>
    <form method="POST" action="{{ route('admin.product-options.update', $option) }}" class="space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        <div class="flex flex-wrap gap-4">
            <label class="block">Name
                <input name="name" value="{{ old('name', $option->name) }}" required maxlength="255" class="mt-1 block w-64 rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            @if ($option->type === ProductOption::QUANTITY)
                <label class="block">Minimum
                    <input type="number" min="0" name="min_quantity" value="{{ old('min_quantity', $option->min_quantity) }}" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Maximum (blank for none)
                    <input type="number" min="1" name="max_quantity" value="{{ old('max_quantity', $option->max_quantity) }}" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            @endif
            <label class="block">Order
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $option->sort_order) }}" class="mt-1 block w-20 rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead class="text-left text-ink-500">
                    <tr>
                        @unless ($option->hasOneChoice())<th class="pr-3">Choice</th>@endunless
                        @foreach ($cycles as $cycle)<th class="pr-3">{{ $cycle->label() }}</th>@endforeach
                        <th class="pr-3">Setup fee</th>
                        @unless ($option->hasOneChoice())<th>Remove</th>@endunless
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $choice)
                        <tr>
                            @unless ($option->hasOneChoice())
                                <td class="py-1 pr-3"><input type="hidden" name="choices[{{ $i }}][id]" value="{{ $choice->id }}"><label for="choice-{{ $i }}" class="sr-only">Choice {{ $i + 1 }} name</label><input id="choice-{{ $i }}" name="choices[{{ $i }}][name]" value="{{ old("choices.{$i}.name", $choice->name) }}" placeholder="{{ $choice->exists ? '' : 'New choice' }}" class="w-40 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                            @endunless
                            @foreach ($cycles as $cycle)
                                <td class="py-1 pr-3">@if ($loop->first && $option->hasOneChoice())<input type="hidden" name="choices[{{ $i }}][id]" value="{{ $choice->id }}">@endif<label for="price-{{ $i }}-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} price</label><input id="price-{{ $i }}-{{ $cycle->value }}" name="choices[{{ $i }}][prices][{{ $cycle->value }}]" value="{{ old("choices.{$i}.prices.{$cycle->value}", isset($choice->prices[$cycle->value]) ? Money::toInput($choice->prices[$cycle->value]) : '') }}" placeholder="0.00" class="w-24 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                            @endforeach
                            <td class="py-1 pr-3"><label for="setup-{{ $i }}" class="sr-only">Setup fee</label><input id="setup-{{ $i }}" name="choices[{{ $i }}][setup_fee]" value="{{ old("choices.{$i}.setup_fee", $choice->setup_fee ? Money::toInput($choice->setup_fee) : '') }}" placeholder="0.00" class="w-24 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                            @unless ($option->hasOneChoice())
                                <td class="py-1">@if ($choice->exists)<label class="flex items-center gap-1"><input type="checkbox" name="choices[{{ $i }}][delete]" value="1"> Remove</label>@endif</td>
                            @endunless
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @foreach ($otherCurrencies as $code)
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <caption class="mb-1 text-left font-medium">{{ $code }}</caption>
                    <thead class="text-left text-ink-500">
                        <tr>
                            @unless ($option->hasOneChoice())<th class="pr-3">Choice</th>@endunless
                            @foreach ($cycles as $cycle)<th class="pr-3">{{ $cycle->label() }}</th>@endforeach
                            <th class="pr-3">Setup fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $choice)
                            @php $saved = $choice->currency_prices[$code] ?? null; @endphp
                            <tr>
                                @unless ($option->hasOneChoice())
                                    <td class="w-40 py-1 pr-3 {{ $choice->exists ? '' : 'text-ink-400' }}">{{ $choice->exists ? $choice->name : 'New choice' }}</td>
                                @endunless
                                @foreach ($cycles as $cycle)
                                    <td class="py-1 pr-3"><label for="price-{{ $code }}-{{ $i }}-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} price in {{ $code }}</label><input id="price-{{ $code }}-{{ $i }}-{{ $cycle->value }}" name="choices[{{ $i }}][currency_prices][{{ $code }}][prices][{{ $cycle->value }}]" value="{{ old("choices.{$i}.currency_prices.{$code}.prices.{$cycle->value}", isset($saved['prices'][$cycle->value]) ? Money::toInput($saved['prices'][$cycle->value]) : '') }}" placeholder="0.00" class="w-24 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                                @endforeach
                                <td class="py-1 pr-3"><label for="setup-{{ $code }}-{{ $i }}" class="sr-only">Setup fee in {{ $code }}</label><input id="setup-{{ $code }}-{{ $i }}" name="choices[{{ $i }}][currency_prices][{{ $code }}][setup_fee]" value="{{ old("choices.{$i}.currency_prices.{$code}.setup_fee", $saved !== null ? Money::toInput($saved['setup_fee'] ?? 0) : '') }}" placeholder="0.00" class="w-24 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
