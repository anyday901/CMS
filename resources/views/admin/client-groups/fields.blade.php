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
            <label class="flex items-center gap-1.5 rounded-full px-2 py-1 ring-1 ring-ink-200 has-[:checked]:ring-2 has-[:checked]:ring-brand-500">
                <input type="radio" name="colour" value="{{ $hex }}" class="sr-only" @checked(old('colour', $group->colour ?? '#64748b') === $hex)>
                <span class="size-3 rounded-full" style="background-color: {{ $hex }}" aria-hidden="true"></span>{{ $name }}
            </label>
        @endforeach
    </div>
</fieldset>
<label class="block">Description <span class="text-ink-500">(optional, for staff)</span>
    <input name="description" value="{{ old('description', $group->description) }}" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
