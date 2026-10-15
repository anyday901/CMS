{{-- $entry, $tasks, $staff --}}
<div class="grid grid-cols-2 gap-3">
    <label class="block">Date
        <input type="date" name="worked_on" value="{{ old('worked_on', $entry->worked_on?->toDateString()) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Time
        <input name="time" value="{{ old('time', $entry->minutes ? App\Addons\ProjectManagement\Duration::format($entry->minutes) : '') }}" required maxlength="20" placeholder="1:30 or 1.5h" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
<label class="block">What was done <span class="text-ink-500">(optional; goes on the invoice)</span>
    <input name="description" value="{{ old('description', $entry->description) }}" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
@if ($tasks->isNotEmpty())
    <label class="block">Task <span class="text-ink-500">(optional)</span>
        <select name="task_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            <option value="">None</option>
            @foreach ($tasks as $task)
                <option value="{{ $task->id }}" @selected((string) old('task_id', $entry->task_id) === (string) $task->id)>{{ $task->title }}</option>
            @endforeach
        </select>
    </label>
@endif
<div class="grid grid-cols-2 gap-3">
    <label class="block">Who
        <select name="user_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            @foreach ($staff as $member)
                <option value="{{ $member->id }}" @selected((string) old('user_id', $entry->user_id) === (string) $member->id)>{{ $member->name }}{{ $member->is(auth()->user()) ? ' (me)' : '' }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Rate <span class="text-ink-500">(blank: the project's)</span>
        <input name="rate" value="{{ old('rate', $entry->exists ? App\Support\Money::toInput($entry->rate) : '') }}" inputmode="decimal" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
<label class="flex items-center gap-2">
    <input type="hidden" name="billable" value="0">
    <input type="checkbox" name="billable" value="1" @checked(old('billable', $entry->billable)) class="rounded">
    Billable
</label>
