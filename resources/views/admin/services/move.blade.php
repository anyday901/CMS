@php use App\Support\Money; $currency = $service->client->currency; @endphp
<x-admin-layout :title="'Move '.$service->description()">
    <p class="mb-2 text-sm"><a href="{{ route('admin.services.show', $service) }}" class="text-brand-600">{{ $service->description() }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Upgrade or downgrade</h1>
    <p class="mb-6 text-sm text-ink-600">Now on {{ $service->product->name }} at {{ Money::format($service->netRecurring(), $currency) }} {{ strtolower($service->billing_cycle->label()) }}, next due {{ $service->next_due_date?->format('M j, Y') ?? '—' }}.</p>

    @if ($problem)
        <p class="mb-6 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">{{ $problem }}@if ($charge && $canMoveFree) You can still move it without charging.@endif</p>
    @endif
    @error('move')<p class="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>@enderror

    <form method="GET" action="{{ route('admin.services.move', $service) }}" class="mb-6 max-w-xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        <label class="block">Product and billing cycle
            <select name="product_cycle" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"
                    onchange="const [p, c] = this.value.split('|'); this.form.product_id.value = p; this.form.billing_cycle.value = c;">
                <option value="">Choose…</option>
                @foreach ($products as $product)
                    <optgroup label="{{ $product->name }}">
                        @foreach ($product->prices->where('currency', $currency)->filter(fn ($price) => $price->billing_cycle->isRecurring()) as $price)
                            <option value="{{ $product->id }}|{{ $price->billing_cycle->value }}" @selected(request('product_id') == $product->id && request('billing_cycle') === $price->billing_cycle->value)>
                                {{ $product->name }}: {{ Money::format($price->price, $currency) }} {{ strtolower($price->billing_cycle->label()) }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </label>
        <input type="hidden" name="product_id" value="{{ request('product_id') }}">
        <input type="hidden" name="billing_cycle" value="{{ request('billing_cycle') }}">
        @foreach ($products as $product)
            @if ($product->options->isNotEmpty())
                <fieldset data-product="{{ $product->id }}" class="space-y-3" hidden>
                    <legend class="mb-1 font-semibold">{{ $product->name }} options</legend>
                    @include('services._options', ['options' => $product->options, 'values' => (array) request('options', [])])
                </fieldset>
            @endif
        @endforeach
        <input type="hidden" name="charge" value="0">
        <label class="flex items-start gap-2"><input type="checkbox" name="charge" value="1" @checked($charge) class="mt-0.5">
            <span>Charge or credit the difference. The unused time is credited and the new price is invoiced for the rest of the period, or for a whole new period from today when the billing cycle changes. Untick to change the price from the next renewal only.</span></label>
        <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">See the price</button>
    </form>

    @if ($quote)
        @php $proration = $quote->proration; @endphp
        <div class="max-w-xl rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <dl class="space-y-2">
                <div class="flex gap-3"><dt class="text-ink-500">New price</dt><dd class="ml-auto">{{ Money::format($quote->newNetRecurring(), $currency) }} {{ strtolower($quote->cycle->label()) }}, from {{ ($quote->nextDue ?? $service->next_due_date)->format('M j, Y') }}</dd></div>
                @if ($charge)
                    <div class="flex gap-3"><dt class="text-ink-500">{{ $quote->product->name }} {{ $quote->nextDue ? 'until '.$quote->nextDue->format('M j, Y') : "for the {$proration->daysLeft} ".Str::plural('day', $proration->daysLeft).' left' }}</dt><dd class="ml-auto">{{ Money::format($proration->charge, $currency) }}</dd></div>
                    <div class="flex gap-3"><dt class="text-ink-500">Unused time on {{ $service->product->name }} ({{ $proration->daysLeft }} of {{ $proration->periodDays }} days)</dt><dd class="ml-auto">-{{ Money::format($proration->credit, $currency) }}</dd></div>
                    <div class="flex gap-3 border-t border-ink-100 pt-2 font-semibold">
                        @if ($quote->net() > 0)
                            <dt>Invoiced now (before tax)</dt><dd class="ml-auto">{{ Money::format($quote->net(), $currency) }}</dd>
                        @else
                            <dt>Added to the client's credit (plus any tax paid on it)</dt><dd class="ml-auto">{{ Money::format(-$quote->net(), $currency) }}</dd>
                        @endif
                    </div>
                @else
                    <div class="flex gap-3"><dt class="text-ink-500">Charged or credited now</dt><dd class="ml-auto">Nothing</dd></div>
                @endif
            </dl>
            <form method="POST" action="{{ route('admin.services.move.store', $service) }}" class="mt-6">@csrf
                <input type="hidden" name="product_id" value="{{ $quote->product->id }}">
                <input type="hidden" name="billing_cycle" value="{{ $quote->cycle->value }}">
                <input type="hidden" name="charge" value="{{ $charge ? 1 : 0 }}">
                @foreach ((array) request('options', []) as $optionId => $value)
                    @if (is_scalar($value))<input type="hidden" name="options[{{ $optionId }}]" value="{{ $value }}">@endif
                @endforeach
                <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">{{ $charge && $quote->net() > 0 ? 'Invoice the move' : 'Move now' }}</button>
                <p class="mt-2 text-ink-500">{{ $charge && $quote->net() > 0 ? 'The move happens once the invoice is paid.' : 'The move happens straight away.' }}</p>
            </form>
        </div>
    @endif

    <script>
        // Show only the chosen product's options; hidden ones are disabled so they aren't sent.
        (() => {
            const form = document.querySelector('form[action="{{ route('admin.services.move', $service) }}"]');
            const sync = () => form.querySelectorAll('[data-product]').forEach(set => {
                set.hidden = set.dataset.product !== form.product_id.value;
                set.disabled = set.hidden;
            });
            form.product_cycle.addEventListener('change', sync);
            sync();
        })();
    </script>
</x-admin-layout>
