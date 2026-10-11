@php use App\Enums\TicketPriority; use App\Enums\TicketStatus; @endphp
<x-admin-layout :title="'Ticket #'.$ticket->id">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">#{{ $ticket->id }} {{ $ticket->subject }}</h1>
        <x-status-badge :status="$ticket->status" />
        <x-status-badge :status="$ticket->priority" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @foreach ($ticket->messages as $message)
                @include('tickets._message', ['download' => fn ($file) => route('admin.tickets.attachment', [$ticket, $file])])
            @endforeach

            <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label for="reply-body" class="block font-display text-base font-semibold">Reply</label>
                    @if ($cannedReplies->isNotEmpty())
                        <label class="flex items-center gap-1.5 text-ink-600">Canned reply
                            <select id="canned-reply" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                                <option value="">Insert…</option>
                                @foreach ($cannedReplies as $canned)
                                    <option value="{{ $loop->index }}" data-body="{{ $canned['body'] }}">{{ $canned['title'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>
                <textarea id="reply-body" name="body" rows="6" required class="block w-full rounded-md px-3 py-2 ring-1 ring-ink-300" placeholder="Write to {{ $ticket->client->first_name }}…">{{ old('body') }}</textarea>
                @include('tickets._attachments')
                <label class="flex items-center gap-2"><input type="checkbox" name="internal" value="1" @checked(old('internal'))> Internal note: only staff see it and nothing is emailed</label>
                <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Send</button>
                <a href="{{ route('admin.canned-replies.index') }}" class="ml-2 text-brand-600">Manage canned replies</a>
            </form>
            @if ($cannedReplies->isNotEmpty())
                <script>
                    // Add the picked canned reply at the end of what's already written.
                    (() => {
                        const select = document.getElementById('canned-reply');
                        const body = document.getElementById('reply-body');
                        select.addEventListener('change', () => {
                            const text = select.selectedOptions[0]?.dataset.body;
                            if (text) {
                                body.value = body.value.trim() === '' ? text : body.value.replace(/\s*$/, '\n\n') + text;
                                body.focus();
                            }
                            select.value = '';
                        });
                    })();
                </script>
            @endif
        </div>

        <aside class="space-y-4">
            <div class="space-y-2 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <div><div class="text-ink-500">Client</div><a href="{{ route('admin.clients.show', $ticket->client) }}" class="text-brand-600">{{ $ticket->client->fullName() }}</a> <span class="text-ink-500">{{ $ticket->client->email }}</span></div>
                <div><div class="text-ink-500">Opened</div>{{ $ticket->created_at->format('M j, Y g:ia') }}</div>
            </div>

            <form method="POST" action="{{ route('admin.tickets.update', $ticket) }}" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                @csrf @method('PUT')
                <label class="block">Status
                    <select name="status" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach (TicketStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($ticket->status === $status)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Priority
                    <select name="priority" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach (TicketPriority::cases() as $priority)
                            <option value="{{ $priority->value }}" @selected($ticket->priority === $priority)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Department
                    <select name="department_id" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected($ticket->department_id === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Assigned to
                    <select name="assigned_to" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="">Nobody</option>
                        @foreach ($staff as $user)
                            <option value="{{ $user->id }}" @selected($ticket->assigned_to === $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Save</button>
            </form>
        </aside>
    </div>
</x-admin-layout>
