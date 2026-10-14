@php use App\Enums\TicketPriority; use App\Enums\TicketStatus; @endphp
<x-admin-layout :title="$rule->exists ? 'Edit escalation rule' : 'New escalation rule'">
    <p class="mb-2 text-sm"><a href="{{ route('admin.escalation-rules.index') }}" class="text-brand-600">Escalation rules</a></p>
    <h1 class="mb-6 text-2xl font-semibold">{{ $rule->exists ? 'Edit escalation rule' : 'New escalation rule' }}</h1>
    @php
        $checked = fn (string $name, $value, ?array $saved) => in_array((string) $value, array_map('strval', (array) old($name, $saved ?? [])), true);
    @endphp
    <form method="POST" action="{{ $rule->exists ? route('admin.escalation-rules.update', $rule) : route('admin.escalation-rules.store') }}" class="max-w-3xl space-y-5 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf
        @if ($rule->exists) @method('PUT') @endif
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block sm:col-span-2">Name
                <input name="name" value="{{ old('name', $rule->name) }}" required maxlength="255" placeholder="Urgent tickets unanswered for an hour" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Order
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $rule->sort_order ?? 0) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        </div>
        <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $rule->active))> Active</label>

        <fieldset class="space-y-3 border-t border-ink-100 pt-4">
            <legend class="font-semibold">When an open ticket is…</legend>
            <p class="text-ink-500">Leave a list unticked to match any.</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <div class="mb-1 text-ink-600">In department</div>
                    @foreach ($departments as $department)
                        <label class="flex items-center gap-2"><input type="checkbox" name="department_ids[]" value="{{ $department->id }}" @checked($checked('department_ids', $department->id, $rule->department_ids))> {{ $department->name }}</label>
                    @endforeach
                </div>
                <div>
                    <div class="mb-1 text-ink-600">With status</div>
                    @foreach (TicketStatus::cases() as $status)
                        @continue($status === TicketStatus::Closed)
                        <label class="flex items-center gap-2"><input type="checkbox" name="statuses[]" value="{{ $status->value }}" @checked($checked('statuses', $status->value, $rule->statuses))> {{ $status->label() }}</label>
                    @endforeach
                </div>
                <div>
                    <div class="mb-1 text-ink-600">With priority</div>
                    @foreach (TicketPriority::cases() as $priority)
                        <label class="flex items-center gap-2"><input type="checkbox" name="priorities[]" value="{{ $priority->value }}" @checked($checked('priorities', $priority->value, $rule->priorities))> {{ $priority->label() }}</label>
                    @endforeach
                </div>
            </div>
            <label class="block">And nobody has replied for at least (minutes)
                <input type="number" min="1" name="minutes_since_reply" value="{{ old('minutes_since_reply', $rule->minutes_since_reply) }}" placeholder="60" class="mt-1 w-32 rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="flex items-center gap-2"><input type="checkbox" name="sla_breached" value="1" @checked(old('sla_breached', $rule->sla_breached))> And it's past its department's response time target (SLA)</label>
        </fieldset>

        <fieldset class="space-y-3 border-t border-ink-100 pt-4">
            <legend class="font-semibold">Then…</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">Move it to
                    <select name="set_department_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="">Don't move</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) old('set_department_id', $rule->set_department_id) === (string) $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Assign it to
                    <select name="assign_to" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="">Don't change</option>
                        @foreach ($staff as $user)
                            <option value="{{ $user->id }}" @selected((string) old('assign_to', $rule->assign_to) === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Set the status to
                    <select name="set_status" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="">Don't change</option>
                        @foreach (TicketStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(old('set_status', $rule->set_status?->value) === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Set the priority to
                    <select name="set_priority" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="">Don't change</option>
                        @foreach (TicketPriority::cases() as $priority)
                            <option value="{{ $priority->value }}" @selected(old('set_priority', $rule->set_priority?->value) === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div>
                <div class="mb-1 text-ink-600">Email</div>
                <label class="flex items-center gap-2"><input type="checkbox" name="notify_assignee" value="1" @checked(old('notify_assignee', $rule->notify_assignee))> The ticket's assignee</label>
                @foreach ($staff as $user)
                    <label class="flex items-center gap-2"><input type="checkbox" name="notify_user_ids[]" value="{{ $user->id }}" @checked($checked('notify_user_ids', $user->id, $rule->notify_user_ids))> {{ $user->name }}</label>
                @endforeach
            </div>
            <label class="block">Reply to the client (optional)
                <textarea name="reply" rows="4" maxlength="20000" placeholder="Thanks for your patience. Your ticket has been passed to our senior team." class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('reply', $rule->reply) }}</textarea>
                <span class="text-ink-500">Posted on the ticket and emailed in {{ config('app.name') }}'s name.</span>
            </label>
        </fieldset>
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $rule->exists ? 'Save' : 'Add rule' }}</button>
    </form>
    @if ($rule->exists)
        <form method="POST" action="{{ route('admin.escalation-rules.destroy', $rule) }}" class="mt-4" onsubmit="return confirm('Delete this rule?')">
            @csrf @method('DELETE')
            <button class="rounded-full px-4 py-2 text-sm text-red-700 ring-1 ring-red-200 hover:bg-red-50">Delete rule</button>
        </form>
    @endif
</x-admin-layout>
