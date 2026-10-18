<label class="block">Name
    <input name="name" value="{{ old('name', $group->name) }}" required maxlength="255" placeholder="Resellers" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<fieldset>
    <legend>Colour</legend>
    <div class="mt-1 flex flex-wrap gap-2">
        @php
            $colours = App\Models\ClientGroup::COLOURS;
            // A colour brought over from WHMCS stays on offer.
            if ($group->colour && ! isset($colours[$group->colour])) {
                $colours = [$group->colour => 'Current'] + $colours;
            }
        @endphp
        @foreach ($colours as $hex => $name)
            <label for="colour-{{ $loop->index }}" class="flex items-center gap-1.5 rounded-full px-2 py-1 ring-1 ring-ink-200 has-[:checked]:ring-2 has-[:checked]:ring-brand-500">
                <input id="colour-{{ $loop->index }}" type="radio" name="colour" value="{{ $hex }}" class="sr-only" @checked(old('colour', $group->colour ?? '#64748b') === $hex)>
                <span class="size-3 rounded-full" style="background-color: {{ $hex }}" aria-hidden="true"></span>{{ $name }}
            </label>
        @endforeach
    </div>
</fieldset>
<label class="block">Description <span class="text-ink-500">(optional, for staff)</span>
    <input name="description" value="{{ old('description', $group->description) }}" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<fieldset class="space-y-2">
    <legend class="mb-1 font-medium">Discount</legend>
    <label class="block">Percent off services
        <input name="discount_percent" value="{{ old('discount_percent', $group->discount_percent ? App\Support\Money::toInput($group->discount_percent) : '') }}" placeholder="10" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        <span class="mt-1 block text-ink-500">Taken off new orders, after any promotion code. Setup fees and domains aren't discounted. Leave blank for none.</span>
    </label>
    <input type="hidden" name="discount_renewals" value="0">
    <label class="flex items-start gap-2"><input type="checkbox" name="discount_renewals" value="1" class="mt-0.5" @checked(old('discount_renewals', $group->discount_renewals))>
        <span>Take it off renewals too</span></label>
</fieldset>
<fieldset class="space-y-2">
    <legend class="mb-1 font-medium">Settings for this group</legend>
    <input type="hidden" name="exempt_late_fees" value="0">
    <label class="flex items-start gap-2"><input type="checkbox" name="exempt_late_fees" value="1" class="mt-0.5" @checked(old('exempt_late_fees', $group->exempt_late_fees))>
        <span>No late fees</span></label>
    <input type="hidden" name="exempt_suspension" value="0">
    <label class="flex items-start gap-2"><input type="checkbox" name="exempt_suspension" value="1" class="mt-0.5" @checked(old('exempt_suspension', $group->exempt_suspension))>
        <span>Never suspend or terminate for overdue invoices</span></label>
    <input type="hidden" name="separate_invoices" value="0">
    <label class="flex items-start gap-2"><input type="checkbox" name="separate_invoices" value="1" class="mt-0.5" @checked(old('separate_invoices', $group->separate_invoices))>
        <span>A separate renewal invoice for each service</span></label>
    <label class="block">Credit limit <span class="text-ink-500">({{ config('billing.currency') }})</span>
        <input name="credit_limit" value="{{ old('credit_limit', $group->credit_limit !== null ? App\Support\Money::toInput($group->credit_limit) : '') }}" placeholder="None" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        <span class="mt-1 block text-ink-500">Clients owing no more than this on unpaid invoices aren't suspended. A client's own limit wins. Leave blank to use the default under Automation.</span>
    </label>
</fieldset>
