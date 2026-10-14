@php use App\Models\NetworkIssue; @endphp
<x-admin-layout :title="$issue->exists ? 'Edit network issue' : 'New network issue'">
    @include('admin.support._nav')
    <h1 class="mb-6 text-2xl font-semibold">{{ $issue->exists ? 'Edit network issue' : 'New network issue' }}</h1>
    <form method="POST" action="{{ $issue->exists ? route('admin.network-issues.update', $issue) : route('admin.network-issues.store') }}" class="max-w-3xl space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf
        @if ($issue->exists) @method('PUT') @endif
        <label class="block">Title
            <input name="title" value="{{ old('title', $issue->title) }}" required maxlength="255" placeholder="Email delays on mail2" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">Server
                <select name="server_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <option value="">None</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected((string) old('server_id', $issue->server_id) === (string) $server->id)>{{ $server->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">Or what it affects
                <input name="affecting" value="{{ old('affecting', $issue->affecting) }}" maxlength="255" placeholder="Email, DNS, the client portal" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Status
                <select name="status" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @foreach (NetworkIssue::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $issue->status) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="text-ink-500">Scheduled issues show as planned maintenance until they start.</span>
            </label>
            <label class="block">Priority
                <select name="priority" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @foreach (NetworkIssue::PRIORITIES as $value => $label)
                        <option value="{{ $value }}" @selected(old('priority', $issue->priority) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">Starts
                <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $issue->starts_at?->format('Y-m-d\TH:i')) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Ends (optional)
                <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $issue->ends_at?->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        </div>
        <label class="block">Details and updates (Markdown)
            <textarea name="description" rows="8" required class="mt-1 w-full rounded-md px-3 py-2 font-mono text-xs ring-1 ring-ink-300">{{ old('description', $issue->description) }}</textarea>
            <span class="text-ink-500">Add updates here as things change; clients see when it was last updated.</span>
        </label>
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $issue->exists ? 'Save' : 'Add issue' }}</button>
    </form>
    @if ($issue->exists)
        <form method="POST" action="{{ route('admin.network-issues.destroy', $issue) }}" class="mt-4" onsubmit="return confirm('Delete this issue?')">
            @csrf @method('DELETE')
            <button class="rounded-full px-4 py-2 text-sm text-red-700 ring-1 ring-red-200 hover:bg-red-50">Delete issue</button>
        </form>
    @endif
</x-admin-layout>
