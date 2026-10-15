<x-admin-layout title="Chat notifications">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Chat notifications</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Post a line to a Slack, Discord or Microsoft Teams channel when something happens, such as a new order, a ticket or a failed provisioning action, with a link to it here.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($channels->isEmpty())
                <p class="text-sm text-ink-500">No channels yet.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Channel</th><th class="px-4 py-2">Events</th><th class="px-4 py-2">Last sent</th><th class="px-4 py-2"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($channels as $item)
                                <tr>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('admin.chat-channels.edit', $item) }}" class="font-medium text-brand-600">{{ $item->name }}</a>
                                        <span class="block text-ink-500">{{ $item->typeLabel() }}{{ $item->active ? '' : ' · off' }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-ink-600">{{ in_array(App\Hooks\Hooks::ANY, $item->events, true) ? 'Every event' : collect($item->events)->map(fn ($key) => $events[$key] ?? $key)->join(', ') }}</td>
                                    <td class="px-4 py-2">
                                        {{ $item->last_sent_at?->diffForHumans() ?? 'Never' }}
                                        @if ($item->last_error)<span class="block max-w-xs truncate text-red-700" title="{{ $item->last_error }}">{{ $item->last_error }}</span>@endif
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <form method="POST" action="{{ route('admin.chat-channels.test', $item) }}">
                                            @csrf
                                            <button class="text-brand-600 hover:underline">Send a test</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.chat-channels.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a channel</h2>
            @include('admin.chat-channels.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add channel</button>
        </form>
    </div>
</x-admin-layout>
