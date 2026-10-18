{{-- The fields of a billable item, for adding one ($item is new) or editing one. Labels wrap their inputs, so several forms can share the page. --}}
@php
    use App\Models\BillableItem;
    use App\Support\Money;
    $isNew = ! $item->exists;
    $value = fn (string $field, $current) => $isNew ? old($field, $current) : $current;
    $input = 'mt-1 block rounded-md px-2 py-1 ring-1 ring-ink-300';
@endphp
<div class="flex flex-wrap items-end gap-2">
    <label class="block grow">Description
        <input name="description" value="{{ $value('description', $item->description) }}" required maxlength="200" placeholder="Website changes" class="{{ $input }} w-full">
    </label>
    <label class="block">Amount
        <input name="amount" value="{{ $value('amount', $item->exists && $item->hours === null ? Money::toInput($item->amount) : '') }}" placeholder="0.00" class="{{ $input }} w-28">
    </label>
    <span class="pb-1.5 text-ink-500">or</span>
    <label class="block">Hours
        <input name="hours" value="{{ $value('hours', $item->hours) }}" placeholder="1.5" class="{{ $input }} w-20">
    </label>
    <label class="block">at hourly rate
        <input name="rate" value="{{ $value('rate', $item->rate !== null ? Money::toInput($item->rate) : '') }}" placeholder="0.00" class="{{ $input }} w-28">
    </label>
</div>
<div class="mt-2 flex flex-wrap items-end gap-2">
    <label class="block">Invoice action
        <select name="invoice_action" class="{{ $input }}">
            @foreach (BillableItem::ACTIONS as $action => $label)
                <option value="{{ $action }}" @selected($value('invoice_action', $item->invoice_action) === $action)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Due date
        <input type="date" name="due_date" value="{{ $value('due_date', $item->due_date?->toDateString() ?? today()->toDateString()) }}" class="{{ $input }}">
    </label>
    <label class="block">Recur every
        <input type="number" name="recur_every" min="1" max="999" value="{{ $value('recur_every', $item->recur_every ?? 1) }}" class="{{ $input }} w-20">
    </label>
    <label class="block"><span class="sr-only">Recurring unit</span>
        <select name="recur_unit" class="{{ $input }}">
            @foreach (BillableItem::UNITS as $unit => $label)
                <option value="{{ $unit }}" @selected($value('recur_unit', $item->recur_unit ?? 'month') === $unit)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Times
        <input type="number" name="recur_times" min="1" max="9999" value="{{ $value('recur_times', $item->recur_times) }}" placeholder="Forever" class="{{ $input }} w-24">
    </label>
    <label class="flex items-center gap-2 pb-1.5"><input type="checkbox" name="taxable" value="1" @checked($isNew ? old('taxable', $item->taxable ?? true) : $item->taxable)> Taxable</label>
</div>
