@php $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout :title="$addon->name()">
    <p class="mb-2 text-sm"><a href="{{ route('admin.addons.index') }}" class="text-brand-600">Add-ons</a></p>
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $addon->name() }}</h1>
        @if ($record->enabled)
            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Enabled</span>
        @else
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Disabled</span>
        @endif
        <form method="POST" action="{{ route($record->enabled ? 'admin.addons.disable' : 'admin.addons.enable', $addon->key()) }}" class="ml-auto">
            @csrf
            <button class="rounded-full px-4 py-1.5 text-sm font-medium {{ $record->enabled ? 'text-ink-700 ring-1 ring-ink-300 hover:bg-ink-50' : 'bg-brand-600 text-white hover:bg-brand-500' }}">{{ $record->enabled ? 'Disable' : 'Enable' }}</button>
        </form>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">{{ $addon->description() }} Version {{ $record->version }}, installed {{ $record->created_at->format('M j, Y') }}.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($addon->settings() === [])
                <p class="rounded-lg border border-ink-200 bg-white p-6 text-sm text-ink-500">This add-on has no settings.</p>
            @else
                <form method="POST" action="{{ route('admin.addons.update', $addon->key()) }}" class="space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
                    @csrf
                    @method('PUT')
                    <h2 class="text-base font-semibold">Settings</h2>
                    @foreach ($addon->settings() as $name => $field)
                        @php $value = old($name, $values[$name] ?? $field['default'] ?? null); @endphp
                        @if ($field['type'] === 'checkbox')
                            <input type="hidden" name="{{ $name }}" value="0">
                            <label class="flex items-start gap-2">
                                <input type="checkbox" name="{{ $name }}" value="1" class="mt-1" @checked($value)>
                                <span>{{ $field['label'] }}@if (! empty($field['help']))<span class="block text-ink-500">{{ $field['help'] }}</span>@endif</span>
                            </label>
                        @else
                            <label class="block">{{ $field['label'] }}
                                @switch($field['type'])
                                    @case('textarea')
                                        <textarea name="{{ $name }}" rows="4" class="{{ $input }}">{{ $value }}</textarea>
                                        @break
                                    @case('select')
                                        <select name="{{ $name }}" class="{{ $input }}">
                                            @foreach ($field['options'] ?? [] as $option => $label)
                                                <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('secret')
                                        <input type="password" name="{{ $name }}" autocomplete="new-password" placeholder="{{ filled($values[$name] ?? null) ? 'Saved; leave blank to keep it' : '' }}" class="{{ $input }}">
                                        @break
                                    @case('number')
                                        <input type="number" step="any" name="{{ $name }}" value="{{ $value }}" class="{{ $input }} max-w-xs">
                                        @break
                                    @default
                                        <input name="{{ $name }}" value="{{ $value }}" class="{{ $input }}">
                                @endswitch
                                @if (! empty($field['help']))<span class="mt-1 block text-ink-500">{{ $field['help'] }}</span>@endif
                            </label>
                        @endif
                    @endforeach
                    <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save settings</button>
                </form>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.addons.destroy', $addon->key()) }}" class="h-fit space-y-3 rounded-lg border border-red-200 bg-white p-4 text-sm" onsubmit="return confirm('Uninstall this add-on?')">
            @csrf
            @method('DELETE')
            <h2 class="font-semibold">Uninstall</h2>
            <p class="text-ink-600">Removes the add-on. Its pages and menu links go away at once.</p>
            <label class="flex items-start gap-2">
                <input type="checkbox" name="remove_data" value="1" class="mt-1">
                <span>Also delete its data. This can't be undone. Leave unticked to keep it for a later reinstall.</span>
            </label>
            <button class="w-full rounded-full px-4 py-2 font-medium text-red-700 ring-1 ring-red-300 hover:bg-red-50">Uninstall</button>
        </form>
    </div>
</x-admin-layout>
