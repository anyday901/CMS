<x-admin-layout title="API keys">
    <p class="mb-2 text-sm"><a href="{{ route('admin.settings.edit') }}" class="text-brand-600">Settings</a></p>
    <h1 class="mb-2 text-2xl font-semibold">API keys</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Let other software, such as an accounting tool or your own scripts, read and change clients, services, invoices and tickets through the REST API. Give each key only what it needs. Everything a key changes shows in the activity log under its name. See <code>docs/api.md</code> for how to use it.</p>

    @if ($new = session('new_key'))
        <div class="mb-6 max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
            <p class="mb-2 font-semibold">Your new API key</p>
            <p class="mb-3 text-ink-700">Copy it somewhere safe now. For your security it won't be shown again; if you lose it, revoke it and make a new one.</p>
            <div class="flex flex-wrap items-center gap-2">
                <input id="new-api-key" readonly value="{{ $new['key'] }}" aria-label="New API key" class="min-w-0 grow rounded-md bg-white px-3 py-2 font-mono text-xs ring-1 ring-ink-300" onclick="this.select()">
                <button type="button" class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100" onclick="navigator.clipboard.writeText(document.getElementById('new-api-key').value); this.textContent = 'Copied'">Copy</button>
            </div>
        </div>
    @endif

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($keys->isEmpty())
                <p class="text-sm text-ink-500">No API keys yet.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Key</th><th class="px-4 py-2">Can</th><th class="px-4 py-2">Last used</th><th class="px-4 py-2">Expires</th><th class="px-4 py-2"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($keys as $apiKey)
                                <tr @class(['align-top', 'text-ink-400' => $apiKey->status() !== 'Active'])>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('admin.api-keys.edit', $apiKey) }}" class="font-medium text-brand-600">{{ $apiKey->name }}</a>
                                        <div class="font-mono text-xs text-ink-500">{{ $apiKey->prefix }}_…</div>
                                        @if ($apiKey->status() !== 'Active')
                                            <span class="mt-1 inline-block rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-600">{{ $apiKey->status() }}</span>
                                        @endif
                                        @if ($apiKey->allowed_ips)
                                            <div class="text-xs text-ink-500">Only from {{ implode(', ', $apiKey->allowed_ips) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-xs">{{ implode(', ', $apiKey->abilities ?? []) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        @if ($apiKey->last_used_at)
                                            <span title="{{ $apiKey->last_used_at->toDayDateTimeString() }}">{{ $apiKey->last_used_at->diffForHumans() }}</span>
                                            <div class="text-xs text-ink-500">{{ $apiKey->last_used_ip }}</div>
                                        @else
                                            <span class="text-ink-500">Never</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2">{{ $apiKey->expires_at?->format('M j, Y') ?? 'Never' }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <div class="flex justify-end gap-3">
                                            @unless ($apiKey->isRevoked())
                                                <form method="POST" action="{{ route('admin.api-keys.revoke', $apiKey) }}" onsubmit="return confirm('Revoke this key? Anything using it stops working straight away.')">
                                                    @csrf
                                                    <button class="text-red-600 hover:underline">Revoke</button>
                                                </form>
                                            @endunless
                                            <form method="POST" action="{{ route('admin.api-keys.destroy', $apiKey) }}" onsubmit="return confirm('Delete this key? Anything using it stops working, and it leaves the list.')">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.api-keys.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Make a key</h2>
            @include('admin.api-keys.fields')
            <label class="block">Expires on
                <input type="date" name="expires_on" value="{{ old('expires_on') }}" min="{{ today()->addDay()->toDateString() }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">It works until the end of that day. Leave it empty for a key that doesn't expire.</span>
            </label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Make key</button>
        </form>
    </div>
</x-admin-layout>
