@php use App\Support\Money; @endphp
<x-admin-layout :title="'Credit note for '.lcfirst($invoice->label())">
    <h1 class="mb-1 text-2xl font-semibold">Credit note for <a href="{{ route('admin.invoices.show', $invoice) }}" class="text-brand-600">{{ lcfirst($invoice->label()) }}</a></h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">For {{ $invoice->client->fullName() }}. Enter how much of each line to give back; tax is worked out at the invoice's rate. Up to {{ Money::format(max(0, $most), $invoice->currency) }} can be credited, what's been paid on the invoice less earlier credit notes. The client sees the reason on the credit note.</p>

    <form method="POST" action="{{ route('admin.credit-notes.store', $invoice) }}" class="max-w-2xl space-y-6 text-sm" onsubmit="return confirm('Issue this credit note and give the money back?')">
        @csrf

        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-3 py-2">Line</th><th class="px-3 py-2 text-right">Charged</th><th class="px-3 py-2 text-right">Left to credit</th><th class="px-3 py-2">Credit</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-3 py-2"><label for="line-{{ $item->id }}">{{ $item->description }}</label>@unless ($item->taxable) <span class="text-ink-500">(not taxed)</span>@endunless</td>
                            <td class="px-3 py-2 text-right">{{ Money::format($item->amount, $invoice->currency) }}</td>
                            <td class="px-3 py-2 text-right">{{ isset($left[$item->id]) ? Money::format($left[$item->id], $invoice->currency) : '—' }}</td>
                            <td class="px-3 py-2">
                                @if (($left[$item->id] ?? 0) > 0)
                                    <input id="line-{{ $item->id }}" name="lines[{{ $item->id }}]" value="{{ old("lines.{$item->id}") }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-ink-300">
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('lines')<p class="text-red-600">{{ $message }}</p>@enderror
        @error('lines.*')<p class="text-red-600">{{ $message }}</p>@enderror

        <label class="block">Reason
            <textarea name="reason" rows="3" required maxlength="2000" placeholder="Refund for the outage on Oct 3" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('reason') }}</textarea>
        </label>
        @error('reason')<p class="text-red-600">{{ $message }}</p>@enderror

        <label class="block">Give the money back
            <select name="refund" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @foreach ($refundModes as $value => $label)
                    <option value="{{ $value }}" @selected(old('refund') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="text-ink-500">Account credit is taken from the invoice's payments, newest first. A refund of one payment needs that payment to have enough left.</span>
        </label>
        @error('refund')<p class="text-red-600">{{ $message }}</p>@enderror

        <div class="flex gap-3">
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Issue credit note</button>
            <a href="{{ route('admin.invoices.show', $invoice) }}" class="rounded-md px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Cancel</a>
        </div>
    </form>
</x-admin-layout>
