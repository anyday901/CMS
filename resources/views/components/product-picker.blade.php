@props(['name', 'products', 'chosen' => []])
@php
    // A collapsible list of product checkboxes, grouped by store group, so a long catalogue
    // doesn't stretch the form. Works as a plain <details> list without JavaScript; the script
    // below adds a filter box, select all and clear buttons, and keeps the summary count current.
    $chosen = array_map('intval', (array) old($name, $chosen));
    $byGroup = $products
        ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
        ->sortBy(fn ($product) => [$product->group === null ? 1 : 0, $product->group?->sort_order ?? 0, $product->group?->name ?? ''])
        ->groupBy(fn ($product) => $product->group?->name ?? 'No group');
    $count = $products->whereIn('id', $chosen)->count();
@endphp
<details data-product-picker class="group/picker relative max-w-md">
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md bg-white px-3 py-2 ring-1 ring-ink-300 hover:ring-ink-400 [&::-webkit-details-marker]:hidden">
        <span data-picker-summary class="min-w-0 truncate">{{ $count ? $count.' selected' : 'None selected' }}</span>
        <span aria-hidden="true" class="text-ink-500 transition group-open/picker:rotate-180">&#9662;</span>
    </summary>
    <div class="mt-1 rounded-md bg-white p-2 shadow-lg ring-1 ring-ink-300">
        <div class="mb-2 space-y-1.5" data-picker-tools hidden>
            <label class="block">
                <span class="sr-only">Filter products</span>
                <input type="search" data-picker-filter placeholder="Filter products" class="w-full rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            </label>
            <div class="flex gap-3 px-1 text-brand-600">
                <button type="button" data-picker-all class="hover:underline">Select all</button>
                <button type="button" data-picker-none class="hover:underline">Clear</button>
            </div>
        </div>
        <div class="max-h-64 space-y-2 overflow-y-auto pr-1">
            @foreach ($byGroup as $groupName => $items)
                <div data-picker-group>
                    <p class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-500">{{ $groupName }}</p>
                    @foreach ($items as $product)
                        <label data-picker-item class="flex items-center gap-2 rounded px-1 py-0.5 hover:bg-ink-50">
                            <input type="checkbox" name="{{ $name }}[]" value="{{ $product->id }}" @checked(in_array($product->id, $chosen, true))>
                            <span>{{ $product->name }}</span>
                        </label>
                    @endforeach
                </div>
            @endforeach
            <p data-picker-empty class="px-1 text-ink-500" hidden>No products match.</p>
        </div>
    </div>
</details>
@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-product-picker]').forEach(picker => {
                const summary = picker.querySelector('[data-picker-summary]');
                const filter = picker.querySelector('[data-picker-filter]');
                const boxes = [...picker.querySelectorAll('input[type=checkbox]')];
                picker.querySelector('[data-picker-tools]').hidden = false;

                const count = () => {
                    const ticked = boxes.filter(box => box.checked);
                    summary.textContent = ticked.length
                        ? ticked.length + ' selected: ' + ticked.map(box => box.nextElementSibling.textContent).join(', ')
                        : 'None selected';
                };
                picker.addEventListener('change', count);
                count();

                filter.addEventListener('input', () => {
                    const term = filter.value.trim().toLowerCase();
                    let shown = 0;
                    picker.querySelectorAll('[data-picker-group]').forEach(group => {
                        let inGroup = 0;
                        group.querySelectorAll('[data-picker-item]').forEach(item => {
                            const match = item.textContent.toLowerCase().includes(term);
                            item.hidden = !match;
                            inGroup += match ? 1 : 0;
                        });
                        group.hidden = inGroup === 0;
                        shown += inGroup;
                    });
                    picker.querySelector('[data-picker-empty]').hidden = shown > 0;
                });
                // Select all and Clear act on the products the filter is showing.
                const selectAll = picker.querySelector('[data-picker-all]');
                const setShown = checked => {
                    boxes.filter(box => !box.closest('[data-picker-item]').hidden).forEach(box => { box.checked = checked; });
                    count();
                };
                selectAll.addEventListener('click', () => setShown(true));
                picker.querySelector('[data-picker-none]').addEventListener('click', () => setShown(false));
                filter.addEventListener('input', () => {
                    selectAll.textContent = filter.value.trim() ? 'Select all shown' : 'Select all';
                });
                filter.addEventListener('keydown', event => {
                    if (event.key === 'Enter') event.preventDefault();
                });

                picker.addEventListener('toggle', () => { if (picker.open) filter.focus(); });
                document.addEventListener('click', event => { if (!picker.contains(event.target)) picker.open = false; });
                picker.addEventListener('keydown', event => {
                    if (event.key === 'Escape' && picker.open) { picker.open = false; picker.querySelector('summary').focus(); }
                });
            });
        });
    </script>
@endonce
