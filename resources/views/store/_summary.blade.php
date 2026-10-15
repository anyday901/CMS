{{-- $quote (App\Ordering\CartQuote), $removable (bool) --}}
@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">{{ __('Item') }}</th><th class="px-4 py-2 text-right">{{ __('Price') }}</th><th class="px-4 py-2 text-right">{{ __('Due today') }}</th>@if ($removable)<th class="px-4 py-2"></th>@endif</tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($quote->rows as $i => $row)
                @php
                    $line = $row['priced']->line;
                    $options = $row['priced']->options->map->label()->join(', ');
                    $first = $i === 0 || $quote->rows[$i - 1]['key'] !== $row['key'];
                @endphp
                <tr>
                    <td class="px-4 py-3 {{ $row['addon'] ? 'pl-8' : '' }}">
                        <div class="font-medium">{{ $row['addon'] ? '+ ' : '' }}{{ $line->product->name }}@if ($line->label) <span class="font-normal text-ink-500">({{ $line->label }})</span>@endif</div>
                        @if ($line->parentService)<div class="text-ink-500">{{ __('For :service', ['service' => $line->parentService->description()]) }}</div>@endif
                        @if ($options !== '')<div class="text-ink-500">{{ $options }}</div>@endif
                        @if ($row['first']->trial)<div class="text-brand-700">{{ __('Free until :date', ['date' => $row['first']->nextDue()->translatedFormat('M j, Y')]) }}</div>
                        @elseif ($row['first']->prorated)<div class="text-ink-500">{{ __('Prorated to :date', ['date' => $row['first']->nextDue()->translatedFormat('M j, Y')]) }}</div>@endif
                        @if ($row['discount'] > 0)<div class="text-brand-700">{{ $quote->promotion->code }}: {{ $quote->promotion->recurring && $line->cycle->isRecurring() ? __(':amount off every payment', ['amount' => Money::format($row['discount'], $quote->currency)]) : __(':amount off', ['amount' => Money::format($row['discount'], $quote->currency)]) }}</div>@endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        {{ Money::format($row['priced']->recurring, $quote->currency) }} <span class="text-ink-500">{{ Str::lower(__($line->cycle->label())) }}</span>
                        @if ($row['priced']->setupFee)<div class="text-ink-500">{{ __('+ :amount setup', ['amount' => Money::format($row['priced']->setupFee, $quote->currency)]) }}</div>@endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">{{ Money::format($row['first']->amount + $row['priced']->setupFee - $row['discount'], $quote->currency) }}</td>
                    @if ($removable)
                        <td class="px-4 py-3 text-right">
                            @if ($first)
                                <form method="POST" action="{{ route('cart.destroy', $row['key']) }}">@csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline">{{ __('Remove') }}</button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t border-ink-200">
            @if ($quote->discount())
                <tr><td class="px-4 py-1 pt-3 text-right" colspan="2">{{ __('Subtotal') }}</td><td class="px-4 py-1 pt-3 text-right">{{ Money::format($quote->subtotal(), $quote->currency) }}</td>@if ($removable)<td></td>@endif</tr>
                <tr><td class="px-4 py-1 text-right" colspan="2">{{ __('Discount (:code)', ['code' => $quote->promotion->code]) }}</td><td class="px-4 py-1 text-right">-{{ Money::format($quote->discount(), $quote->currency) }}</td>@if ($removable)<td></td>@endif</tr>
            @endif
            <tr class="font-semibold"><td class="px-4 py-3 text-right" colspan="2">{{ __('Total due today') }}</td><td class="px-4 py-3 text-right">{{ Money::format($quote->total(), $quote->currency) }}</td>@if ($removable)<td></td>@endif</tr>
        </tfoot>
    </table>
</div>
<p class="mt-2 text-xs text-ink-500">{{ __('Before tax. Any tax for your address is added to the invoice.') }}</p>
