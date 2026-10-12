@php use App\Models\Promotion; use App\Support\Money; @endphp
<label class="block">Code
    <input name="code" value="{{ old('code', $promotion->code) }}" required maxlength="50" placeholder="SPRING25" class="mt-1 w-full rounded-md px-3 py-2 uppercase ring-1 ring-ink-300">
</label>
<div class="grid grid-cols-2 gap-3">
    <label class="block">Type
        <select name="type" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            @foreach (Promotion::TYPES as $value => $label)
                <option value="{{ $value }}" @selected(old('type', $promotion->type) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Discount (% or {{ config('billing.currency') }})
        <input name="value" value="{{ old('value', $promotion->exists ? Money::toInput($promotion->value) : '') }}" required placeholder="10" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
<input type="hidden" name="recurring" value="0">
<label class="flex items-start gap-2"><input type="checkbox" name="recurring" value="1" class="mt-0.5" @checked(old('recurring', $promotion->recurring))>
    <span>Recurring: take it off every renewal, not just the first payment</span></label>
<fieldset>
    <legend class="mb-1">Applies to (none ticked for every product)</legend>
    <div class="max-h-40 space-y-1 overflow-y-auto rounded-md p-2 ring-1 ring-ink-200">
        @forelse ($products as $id => $name)
            <label class="flex items-center gap-2"><input type="checkbox" name="product_ids[]" value="{{ $id }}" @checked(in_array($id, array_map('intval', (array) old('product_ids', $promotion->product_ids ?? []))))> {{ $name }}</label>
        @empty
            <span class="text-ink-500">No products yet.</span>
        @endforelse
    </div>
</fieldset>
<div class="grid grid-cols-2 gap-3">
    <label class="block">Starts (optional)
        <input type="date" name="starts_on" value="{{ old('starts_on', $promotion->starts_on?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Expires after (optional)
        <input type="date" name="expires_on" value="{{ old('expires_on', $promotion->expires_on?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
<label class="block">Maximum uses (blank for no limit)
    <input type="number" name="max_uses" min="1" value="{{ old('max_uses', $promotion->max_uses) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<input type="hidden" name="once_per_client" value="0">
<label class="flex items-center gap-2"><input type="checkbox" name="once_per_client" value="1" @checked(old('once_per_client', $promotion->once_per_client))> Once per client</label>
<input type="hidden" name="active" value="0">
<label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $promotion->exists ? $promotion->active : true))> Active</label>
<label class="block">Notes (staff only)
    <textarea name="notes" rows="2" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('notes', $promotion->notes) }}</textarea>
</label>
