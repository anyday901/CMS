@php use App\Addons\ProjectManagement\Models\Project; use App\Support\Money; @endphp
<x-admin-layout :title="$project->exists ? 'Edit project' : 'New project'">
    <p class="mb-2 text-sm"><a href="{{ $project->exists ? route('admin.projects.show', $project) : route('admin.projects.index') }}" class="text-brand-600">{{ $project->exists ? $project->title : 'Projects' }}</a></p>
    <h1 class="mb-6 text-2xl font-semibold">{{ $project->exists ? 'Edit project' : 'New project' }}</h1>

    <form method="POST" action="{{ $project->exists ? route('admin.projects.update', $project) : route('admin.projects.store') }}" class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @if ($project->exists) @method('PUT') @endif
        <label class="block">Title
            <input name="title" value="{{ old('title', $project->title) }}" required maxlength="255" placeholder="Website redesign" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <label class="block">Description <span class="text-ink-500">(optional; clients see this if projects are shown in the portal)</span>
            <textarea name="description" rows="4" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $project->description) }}</textarea>
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">Client <span class="text-ink-500">(client number; blank for an internal project)</span>
                <input type="number" name="client_id" min="1" value="{{ old('client_id', $project->client_id) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @if ($project->client)<span class="mt-1 block text-ink-500">{{ $project->client->fullName() }}</span>@endif
            </label>
            <label class="block">Owner
                <select name="user_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <option value="">No one</option>
                    @foreach ($staff as $member)
                        <option value="{{ $member->id }}" @selected((string) old('user_id', $project->user_id) === (string) $member->id)>{{ $member->name }}{{ $member->is(auth()->user()) ? ' (me)' : '' }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">Status
                <select name="status" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @foreach (Project::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $project->status) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">Due <span class="text-ink-500">(optional)</span>
                <input type="date" name="due_on" value="{{ old('due_on', $project->due_on?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Hourly rate <span class="text-ink-500">(in the client's currency)</span>
                <input name="hourly_rate" value="{{ old('hourly_rate', Money::toInput((int) $project->hourly_rate)) }}" inputmode="decimal" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        </div>
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $project->exists ? 'Save' : 'Create project' }}</button>
    </form>

    @if ($project->exists)
        <form method="POST" action="{{ route('admin.projects.destroy', $project) }}" class="mt-4" onsubmit="return confirm('Delete this project with its tasks and time? Invoices already made are kept.')">
            @csrf @method('DELETE')
            <button class="text-sm text-red-600 hover:underline">Delete this project</button>
        </form>
    @endif
</x-admin-layout>
