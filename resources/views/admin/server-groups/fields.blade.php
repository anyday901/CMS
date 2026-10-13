@php $chosen = array_map('intval', (array) old('servers', $group->exists ? $group->servers->pluck('id')->all() : [])); @endphp
<label class="block">Name
    <input name="name" value="{{ old('name', $group->name) }}" required placeholder="Shared hosting" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<fieldset class="space-y-1">
    <legend class="mb-1">Fill type</legend>
    @foreach (App\Models\ServerGroup::FILL_TYPES as $type => $label)
        <label class="flex items-start gap-2"><input type="radio" name="fill_type" value="{{ $type }}" class="mt-0.5" @checked(old('fill_type', $group->fill_type) === $type)> <span>{{ $label }}</span></label>
    @endforeach
</fieldset>
<fieldset class="space-y-1">
    <legend class="mb-1">Servers</legend>
    @forelse ($servers as $server)
        <label class="flex items-center gap-2"><input type="checkbox" name="servers[]" value="{{ $server->id }}" @checked(in_array($server->id, $chosen, true))> {{ $server->name }} <span class="text-ink-500">({{ $server->hostname }})</span></label>
    @empty
        <p class="text-ink-500">Add a server first.</p>
    @endforelse
</fieldset>
