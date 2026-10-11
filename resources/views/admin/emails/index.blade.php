<x-admin-layout title="Email log">
    <h1 class="mb-4 text-2xl font-semibold">Email log</h1>
    <form method="GET" class="mb-4 flex flex-wrap gap-2 text-sm">
        <label for="email-search" class="sr-only">Search</label>
        <input id="email-search" name="q" value="{{ $search }}" placeholder="Search address or subject" class="w-72 rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        <label for="email-status" class="sr-only">Status</label>
        <select id="email-status" name="status" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            <option value="">Any status</option>
            @foreach (['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Filter</button>
        <a href="{{ route('admin.email-templates.index') }}" class="ml-auto self-center text-brand-600">Email templates</a>
    </form>
    @if ($emails->isEmpty())
        <p class="text-sm text-ink-500">No emails yet.</p>
    @else
        @include('admin.emails._table')
        <div class="mt-4">{{ $emails->links() }}</div>
    @endif
</x-admin-layout>
