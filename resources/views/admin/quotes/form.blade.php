@php use App\Enums\QuoteStage; use App\Support\Money; @endphp
<x-admin-layout :title="$quote->exists ? 'Edit quote #'.$quote->number : 'New quote'">
    <h1 class="mb-1 text-2xl font-semibold">{{ $quote->exists ? 'Edit quote #'.$quote->number : 'New quote' }}</h1>
    <p class="mb-6 text-sm text-ink-600">For <a href="{{ route('admin.clients.show', $client) }}" class="text-brand-600">{{ $client->fullName() }}</a>, in {{ $quote->currency ?? $client->currency }}. Tax is worked out from the client's address and your tax rules, the same way as on invoices. Use a negative amount for a discount.</p>

    <form method="POST" action="{{ $quote->exists ? route('admin.quotes.update', $quote) : route('admin.quotes.store', $client) }}" class="space-y-6 text-sm">
        @csrf
        @if ($quote->exists) @method('PUT') @endif

        <div class="grid max-w-3xl gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">Subject
                <input name="subject" value="{{ old('subject', $quote->subject) }}" required maxlength="255" placeholder="Website redesign" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Quote date
                <input type="date" name="issue_date" value="{{ old('issue_date', $quote->issue_date?->toDateString()) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Valid until
                <input type="date" name="valid_until" value="{{ old('valid_until', $quote->valid_until?->toDateString()) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Stage
                <select name="stage" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @foreach (QuoteStage::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('stage', $quote->stage?->value) === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-ink-500">Delivered and on hold quotes show in the client's portal, where they can accept them. Send to client sets this for you.</span>
            </label>
        </div>

        @php
            $rows = old('items', $items->map(fn ($item) => [
                'id' => $item->id, 'description' => $item->description,
                'amount' => Money::toInput($item->amount), 'taxable' => $item->taxable,
            ])->all());
            $rows = array_values($rows);
            if (count($rows) === 0) { $rows[] = ['taxable' => true]; }
        @endphp
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-3 py-2">Description</th><th class="px-3 py-2">Amount</th><th class="px-3 py-2">Taxable</th><th></th></tr></thead>
                <tbody id="item-rows">
                    @foreach ($rows as $i => $row)
                        <tr class="item-row">
                            <td class="px-3 py-2">
                                <input type="hidden" name="items[{{ $i }}][id]" value="{{ $row['id'] ?? '' }}">
                                <label class="sr-only" for="item-{{ $i }}-description">Description</label>
                                <input id="item-{{ $i }}-description" name="items[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" class="w-full min-w-64 rounded-md px-2 py-1 ring-1 ring-ink-300">
                            </td>
                            <td class="px-3 py-2">
                                <label class="sr-only" for="item-{{ $i }}-amount">Amount</label>
                                <input id="item-{{ $i }}-amount" name="items[{{ $i }}][amount]" value="{{ $row['amount'] ?? '' }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-ink-300">
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="hidden" name="items[{{ $i }}][taxable]" value="0">
                                <input type="checkbox" name="items[{{ $i }}][taxable]" value="1" aria-label="Taxable" @checked($row['taxable'] ?? true)>
                            </td>
                            <td class="px-3 py-2"><button type="button" class="remove-row text-red-600 hover:underline">Remove</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button type="button" id="add-row" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Add line</button>

        <div class="grid max-w-3xl gap-4 sm:grid-cols-2">
            <label class="block">Proposal (shown to the client)
                <textarea name="notes" rows="6" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300" placeholder="What's included, timescales, terms">{{ old('notes', $quote->notes) }}</textarea>
            </label>
            <label class="block">Admin notes (staff only)
                <textarea name="admin_notes" rows="6" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('admin_notes', $quote->admin_notes) }}</textarea>
            </label>
        </div>

        <div class="flex gap-3">
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $quote->exists ? 'Save changes' : 'Create quote' }}</button>
            <a href="{{ $quote->exists ? route('admin.quotes.show', $quote) : route('admin.clients.show', [$client, 'tab' => 'quotes']) }}" class="rounded-md px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-100">Cancel</a>
        </div>
    </form>

    <script>
        (() => {
            const body = document.getElementById('item-rows');
            let next = body.querySelectorAll('.item-row').length;
            document.getElementById('add-row').addEventListener('click', () => {
                const row = body.querySelector('.item-row').cloneNode(true);
                row.querySelectorAll('input, label').forEach((el) => {
                    for (const attr of ['name', 'id', 'for']) {
                        if (el.hasAttribute(attr)) el.setAttribute(attr, el.getAttribute(attr).replace(/(items\[|item-)\d+/, `$1${next}`));
                    }
                    if (el.type === 'checkbox') el.checked = true;
                    else if (el.tagName === 'INPUT' && el.type !== 'hidden') el.value = '';
                    else if (el.type === 'hidden' && el.name.endsWith('[id]')) el.value = '';
                });
                body.appendChild(row);
                next++;
            });
            body.addEventListener('click', (event) => {
                if (!event.target.classList.contains('remove-row')) return;
                const rows = body.querySelectorAll('.item-row');
                const row = event.target.closest('tr');
                if (rows.length > 1) row.remove();
                else row.querySelectorAll('input:not([type=hidden]):not([type=checkbox])').forEach((el) => el.value = '');
            });
        })();
    </script>
</x-admin-layout>
