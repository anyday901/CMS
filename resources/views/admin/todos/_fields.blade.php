{{-- $todo, $staff --}}
<label class="block">What needs doing
    <input name="title" value="{{ old('title', $todo->title) }}" required maxlength="255" placeholder="Call back about the migration" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Details <span class="text-ink-500">(optional)</span>
    <textarea name="description" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $todo->description) }}</textarea>
</label>
<div class="grid gap-3 sm:grid-cols-2">
    <label class="block">For
        <select name="user_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            <option value="">Anyone</option>
            @foreach ($staff as $member)
                <option value="{{ $member->id }}" @selected((string) old('user_id', $todo->user_id) === (string) $member->id)>{{ $member->name }}{{ $member->is(auth()->user()) ? ' (me)' : '' }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Due <span class="text-ink-500">(optional)</span>
        <input type="date" name="due_on" value="{{ old('due_on', $todo->due_on?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    @if ($todo->exists)
        <label class="block">Status
            <select name="status" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @foreach (App\Models\Todo::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $todo->status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label class="block">About client <span class="text-ink-500">(optional, client number)</span>
        <input type="number" name="client_id" min="1" value="{{ old('client_id', $todo->client_id) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
