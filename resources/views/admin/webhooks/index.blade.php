<x-admin-layout title="Webhooks">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Webhooks</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">When something happens, such as an invoice being paid or a ticket opened, each webhook subscribed to it gets a JSON POST, signed with its secret in the <code class="rounded bg-ink-100 px-1">X-Webhook-Signature</code> header. Failed deliveries are tried again for a few hours. See <code class="rounded bg-ink-100 px-1">docs/webhooks.md</code> for the format.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($webhooks->isEmpty())
                <p class="text-sm text-ink-500">No webhooks yet.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Webhook</th><th class="px-4 py-2">Events</th><th class="px-4 py-2">Status</th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($webhooks as $hook)
                                <tr>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('admin.webhooks.edit', $hook) }}" class="font-medium text-brand-600">{{ $hook->name }}</a>
                                        <span class="block max-w-xs truncate text-ink-500">{{ $hook->url }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-ink-600">{{ $hook->listensToEverything() ? 'Every event' : count($hook->events).' '.Str::plural('event', count($hook->events)) }}</td>
                                    <td class="px-4 py-2">
                                        {{ $hook->active ? 'Active' : 'Off' }}
                                        @if ($hook->failed_count)<span class="block text-red-700">{{ $hook->failed_count }} failed</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.webhooks.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a webhook</h2>
            @include('admin.webhooks.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add webhook</button>
        </form>
    </div>
</x-admin-layout>
