<x-admin-layout title="Email templates">
    <h1 class="mb-2 text-2xl font-semibold">Email templates</h1>
    <p class="mb-4 max-w-3xl text-sm text-ink-600">These are the emails clients get from the app. Edit the wording, or turn an email off. Every email sent is listed in the <a href="{{ route('admin.emails.index') }}" class="text-brand-600">email log</a>.</p>
    <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Email</th><th class="px-4 py-2">When it's sent</th><th class="px-4 py-2">Status</th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($definitions as $key => $definition)
                    @php $template = $saved->get($key); @endphp
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2"><a href="{{ route('admin.email-templates.edit', $key) }}" class="text-brand-600">{{ $definition['name'] }}</a></td>
                        <td class="px-4 py-2 text-ink-600">{{ $definition['description'] }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ $template ? ($template->enabled ? 'Edited' : 'Off') : 'Default' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin-layout>
