<x-admin-layout title="Currencies">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Currencies</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">Each client is billed in one currency, and the store shows guests a currency picker when there is more than one here. A rate is how many of that currency make one {{ $currencies->firstWhere('is_default', true)?->code ?? config('billing.currency') }}, the default currency. Prices are set separately for each currency on the product, option and TLD pages; <strong>Update product prices</strong> fills them all in from the default currency's prices at these rates.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="space-y-4 md:col-span-2">
            <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-ink-500"><tr><th scope="col" class="px-4 py-2">Currency</th><th scope="col" class="px-4 py-2">Example</th><th scope="col" class="px-4 py-2 text-right">Rate</th><th scope="col" class="px-4 py-2">Rate updated</th><th scope="col" class="px-4 py-2 text-right">Clients</th><th scope="col" class="px-4 py-2"></th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($currencies as $currency)
                            <tr>
                                <td class="px-4 py-2 font-medium">
                                    @if ($currency->is_default)
                                        {{ $currency->code }} <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">Default</span>
                                    @else
                                        <a href="{{ route('admin.currencies.edit', $currency) }}" class="text-brand-600">{{ $currency->code }}</a>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-ink-600">{{ App\Support\Money::format(123456, $currency->code) }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $currency->is_default ? '1' : $currency->rate }}</td>
                                <td class="px-4 py-2 text-ink-600">{{ $currency->is_default ? '—' : ($currency->rate_updated_at?->format('M j, Y g:i a') ?? 'Never') }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($clientCounts[$currency->code] ?? 0) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    @unless ($currency->is_default)
                                        <form method="POST" action="{{ route('admin.currencies.default', $currency) }}" class="inline" onsubmit="return confirm('Make {{ $currency->code }} the default currency? Every rate is restated against it. Clients keep their currency.')">
                                            @csrf
                                            <button class="text-brand-600 hover:underline">Make default</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.currencies.destroy', $currency) }}" class="ml-3 inline" onsubmit="return confirm('Delete {{ $currency->code }} and every price in it?')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline">Delete</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('admin.currencies.rates') }}">
                    @csrf
                    <button class="rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500" @disabled($currencies->count() < 2)>Update exchange rates</button>
                </form>
                <form method="POST" action="{{ route('admin.currencies.prices') }}" onsubmit="return confirm('Replace every product, option and TLD price in the other currencies with the default currency\'s price converted at these rates?')">
                    @csrf
                    <button class="rounded-full px-4 py-2 text-sm font-medium ring-1 ring-ink-300 hover:bg-ink-100" @disabled($currencies->count() < 2)>Update product prices</button>
                </form>
            </div>
            <div class="max-w-3xl space-y-2 text-sm text-ink-600">
                <p><strong>Update exchange rates</strong> reads today's reference rates from the European Central Bank. It quotes about 30 currencies; any other keeps the rate you set. {{ $autoUpdate ? 'Rates are also updated automatically once a day.' : 'Rates aren\'t updated automatically.' }} Change that under <a href="{{ route('admin.automation.edit') }}" class="text-brand-600">Automation settings</a>.</p>
                <p><strong>Update product prices</strong> sets every product, configurable option and TLD price in each other currency to the default currency's price converted at its rate, rounded to the nearest cent. Prices that exist only in another currency are left alone. Services already ordered keep the price they have.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.currencies.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a currency</h2>
            <label class="block">Code
                <input name="code" value="{{ old('code') }}" required maxlength="3" placeholder="EUR" class="mt-1 w-full rounded-md px-3 py-2 uppercase ring-1 ring-ink-300">
                <span class="text-ink-500">The three-letter ISO 4217 code.</span>
            </label>
            <label class="block">Exchange rate
                <input name="rate" value="{{ old('rate') }}" inputmode="decimal" placeholder="1" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">How many make one {{ config('billing.currency') }}. Leave blank and use Update exchange rates.</span>
            </label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add currency</button>
        </form>
    </div>
</x-admin-layout>
