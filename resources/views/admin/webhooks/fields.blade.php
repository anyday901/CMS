@php $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<label class="block">Name
    <input name="name" value="{{ old('name', $webhook->name) }}" required maxlength="255" placeholder="Accounting sync" class="{{ $input }}">
</label>
<label class="block">URL
    <input type="url" name="url" value="{{ old('url', $webhook->url) }}" required maxlength="2000" placeholder="https://example.com/hooks/billing" class="{{ $input }}">
</label>
@include('admin.integrations.events', ['selected' => old('events', $webhook->events ?? [])])
<input type="hidden" name="active" value="0">
<label class="flex items-center gap-2">
    <input type="checkbox" name="active" value="1" @checked(old('active', $webhook->active))>
    <span>Active</span>
</label>
