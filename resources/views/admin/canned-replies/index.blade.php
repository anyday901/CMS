<x-admin-layout title="Canned replies">
    <h1 class="mb-2 text-2xl font-semibold">Canned replies</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Saved answers to common questions. Pick one under a ticket's reply box to drop it in, then edit it before sending.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($replies->isEmpty())
                <p class="text-sm text-ink-500">No canned replies yet.</p>
            @else
                <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                    @foreach ($replies as $item)
                        <div class="flex items-start gap-4 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('admin.canned-replies.edit', $item) }}" class="font-medium text-brand-600">{{ $item->title }}</a>
                                <p class="truncate text-ink-500">{{ Str::limit($item->body, 140) }}</p>
                            </div>
                            <form method="POST" action="{{ route('admin.canned-replies.destroy', $item) }}" onsubmit="return confirm('Delete this canned reply?')">
                                @csrf @method('DELETE')
                                <button class="rounded-full px-3 py-1 text-ink-600 ring-1 ring-ink-300 hover:bg-ink-100">Delete</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.canned-replies.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a canned reply</h2>
            @include('admin.canned-replies.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add canned reply</button>
        </form>
    </div>
</x-admin-layout>
