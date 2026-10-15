<x-admin-layout :title="$channel->name">
    <p class="mb-2 text-sm"><a href="{{ route('admin.chat-channels.index') }}" class="text-brand-600">Chat notifications</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $channel->name }}</h1>
        <form method="POST" action="{{ route('admin.chat-channels.test', $channel) }}" class="ml-auto">
            @csrf
            <button class="rounded-full px-4 py-1.5 text-sm font-medium text-ink-700 ring-1 ring-ink-300 hover:bg-ink-50">Send a test</button>
        </form>
        <form method="POST" action="{{ route('admin.chat-channels.destroy', $channel) }}" onsubmit="return confirm('Delete this channel?')">
            @csrf @method('DELETE')
            <button class="rounded-full px-4 py-1.5 text-sm font-medium text-red-700 ring-1 ring-red-300 hover:bg-red-50">Delete</button>
        </form>
    </div>
    <form method="POST" action="{{ route('admin.chat-channels.update', $channel) }}" class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.chat-channels.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
