{{-- $options (with choices), $values (option id => value), $cycleHint (optional BillingCycle for prices) --}}
@php use App\Models\ProductOption; use App\Support\Money; @endphp
@foreach ($options as $option)
    @php
        $value = old("options.{$option->id}", $values[$option->id] ?? null);
        $price = fn ($choice) => isset($cycleHint) && $choice->priceFor($cycleHint) ? ' (+'.Money::format($choice->priceFor($cycleHint)).')' : '';
    @endphp
    @if ($option->type === ProductOption::DROPDOWN)
        <label class="block">{{ $option->name }}
            <select name="options[{{ $option->id }}]" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @foreach ($option->choices as $choice)
                    <option value="{{ $choice->id }}" @selected((string) $value === (string) $choice->id)>{{ $choice->name }}{{ $price($choice) }}</option>
                @endforeach
            </select>
        </label>
    @elseif ($option->type === ProductOption::CHECKBOX)
        <div>
            <input type="hidden" name="options[{{ $option->id }}]" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="options[{{ $option->id }}]" value="1" @checked((string) $value === '1')> {{ $option->name }}{{ $option->choices->first() ? $price($option->choices->first()) : '' }}</label>
        </div>
    @else
        <label class="block">{{ $option->name }}{{ $option->choices->first() ? $price($option->choices->first()) : '' }}{{ $option->choices->first() && isset($cycleHint) && $option->choices->first()->priceFor($cycleHint) ? ' each' : '' }}
            <input type="number" name="options[{{ $option->id }}]" value="{{ $value ?? $option->min_quantity }}" min="{{ $option->min_quantity }}" @if ($option->max_quantity !== null) max="{{ $option->max_quantity }}" @endif class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
    @endif
@endforeach
