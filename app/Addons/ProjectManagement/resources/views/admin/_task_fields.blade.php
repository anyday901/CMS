{{-- $task, $staff --}}
<label class="block">Task
    <input name="title" value="{{ old('title', $task->title) }}" required maxlength="255" placeholder="Send the first design" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Notes <span class="text-ink-500">(optional; staff only)</span>
    <textarea name="notes" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('notes', $task->notes) }}</textarea>
</label>
<div class="grid gap-3 sm:grid-cols-3">
    <label class="block">For
        <select name="user_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            <option value="">Anyone</option>
            @foreach ($staff as $member)
                <option value="{{ $member->id }}" @selected((string) old('user_id', $task->user_id) === (string) $member->id)>{{ $member->name }}{{ $member->is(auth()->user()) ? ' (me)' : '' }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Due <span class="text-ink-500">(optional)</span>
        <input type="date" name="due_on" value="{{ old('due_on', $task->due_on?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Status
        <select name="status" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            @foreach (App\Addons\ProjectManagement\Models\Task::STATUSES as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $task->status) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
</div>
