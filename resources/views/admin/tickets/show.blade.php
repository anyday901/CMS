@php use App\Enums\TicketPriority; use App\Enums\TicketStatus; @endphp
<x-admin-layout :title="'Ticket #'.$ticket->id">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">#{{ $ticket->id }} {{ $ticket->subject }}</h1>
        <x-status-badge :status="$ticket->status" />
        <x-status-badge :status="$ticket->priority" />
        @foreach ($ticket->tags as $tag)
            <a href="{{ route('admin.tickets.index', ['status' => 'all', 'tag' => $tag->name]) }}" class="rounded-full bg-ink-100 px-2.5 py-0.5 text-xs font-medium text-ink-700 hover:bg-ink-200">{{ $tag->name }}</a>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @foreach ($ticket->messages as $message)
                @include('tickets._message', ['download' => fn ($file) => route('admin.tickets.attachment', [$ticket, $file]), 'splittable' => $ticket->messages->count() > 1])
            @endforeach

            @if ($ticket->messages->count() > 1)
                <details id="split" class="rounded-lg border border-ink-200 bg-white p-4 text-sm" @if ($errors->has('messages') || $errors->has('split_subject')) open @endif>
                    <summary class="cursor-pointer font-display text-base font-semibold">Split into a new ticket</summary>
                    <form id="split-form" method="POST" action="{{ route('admin.tickets.split', $ticket) }}" class="mt-3 space-y-3">
                        @csrf
                        <p class="text-ink-600">Tick the messages above that belong in a ticket of their own. They move to a new ticket for {{ $ticket->requesterFirstName() }}, and both tickets get a note linking them.</p>
                        @error('messages')<p class="text-red-700">{{ $message }}</p>@enderror
                        <label class="block">Subject
                            <input name="split_subject" value="{{ old('split_subject', $ticket->subject) }}" required maxlength="250" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">Department
                                <select name="split_department_id" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}" @selected((int) old('split_department_id', $ticket->department_id) === $department->id)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">Priority
                                <select name="split_priority" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                                    @foreach (TicketPriority::cases() as $priority)
                                        <option value="{{ $priority->value }}" @selected(old('split_priority', $ticket->priority->value) === $priority->value)>{{ $priority->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        @if ($ticket->isGuest())
                            <p class="text-sm text-ink-600">{{ $ticket->requesterFirstName() }} is emailed a link to the new ticket, since guests have no ticket list to find it in.</p>
                        @else
                            <label for="split-notify" class="flex items-center gap-2"><input id="split-notify" type="checkbox" name="notify" value="1" @checked(old('notify'))> Email {{ $ticket->requesterFirstName() }} the new ticket number</label>
                        @endif
                        <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Split ticked messages</button>
                    </form>
                </details>
                <script>
                    // The boxes to tick messages show while the split form is open.
                    (() => {
                        const panel = document.getElementById('split');
                        const show = () => document.querySelectorAll('.split-pick').forEach((box) => box.classList.toggle('hidden', !panel.open));
                        panel.addEventListener('toggle', show);
                        show();
                    })();
                </script>
            @endif

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
                <textarea id="reply-body" name="body" rows="6" required class="block w-full rounded-md px-3 py-2 ring-1 ring-ink-300" placeholder="Write to {{ $ticket->requesterFirstName() }}…">{{ old('body') }}</textarea>
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
                @if ($ticket->isGuest())
                    <div><div class="text-ink-500">From a guest</div>{{ $ticket->guest_name }} <span class="text-ink-500">{{ $ticket->guest_email }}</span></div>
                @else
                    <div><div class="text-ink-500">Client</div><a href="{{ route('admin.clients.show', $ticket->client) }}" class="text-brand-600">{{ $ticket->client->fullName() }}</a> <span class="text-ink-500">{{ $ticket->client->email }}</span></div>
                @endif
                <div><div class="text-ink-500">Opened</div>{{ $ticket->created_at->format('M j, Y g:ia') }}</div>
                <div><div class="text-ink-500">Response due</div>@include('admin.tickets._due')</div>
                @if ($ticket->closed_at)
                    <div><div class="text-ink-500">Closed</div>{{ $ticket->closed_at->format('M j, Y g:ia') }}</div>
                @endif
                @if ($ticket->first_response_at)
                    <div><div class="text-ink-500">First answered</div>{{ $ticket->first_response_at->format('M j, Y g:ia') }} ({{ $ticket->first_response_at->diffForHumans($ticket->created_at, syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE) }} after opening)</div>
                @endif
            </div>

            @if ($ticket->isGuest())
                <form method="POST" action="{{ route('admin.tickets.client', $ticket) }}" class="space-y-2 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    @csrf
                    <label for="link-client" class="block font-semibold">Move to a client account</label>
                    <p class="text-ink-600">For a guest who has an account, or has just signed up. The ticket moves to the client and the guest's private link stops working.</p>
                    <input id="link-client" name="client_email" type="email" value="{{ old('client_email', $matchingClient?->email) }}" placeholder="Client's email address" required class="block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @if ($matchingClient)<p class="text-ink-600">{{ $matchingClient->fullName() }} has an account with this guest's address.</p>@endif
                    @error('client_email')<p class="text-red-700">{{ $message }}</p>@enderror
                    <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Move to client</button>
                </form>
            @endif

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
                <label class="block">Tags
                    <input name="tags" value="{{ old('tags', $ticket->tags->pluck('name')->join(', ')) }}" list="ticket-tags" placeholder="billing, outage" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <span class="text-ink-500">Separate tags with commas.</span>
                </label>
                <datalist id="ticket-tags">
                    @foreach ($allTags as $tag)<option value="{{ $tag }}"></option>@endforeach
                </datalist>
                @if ($customFields->isNotEmpty())
                    <div class="space-y-3 border-t border-ink-100 pt-3">
                        @include('custom-fields._inputs', ['fields' => $customFields, 'values' => $ticket->custom_fields ?? []])
                    </div>
                @endif
                <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Save</button>
            </form>

            @if ($ticket->feedback)
                <div class="space-y-1 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    <div class="font-semibold">{{ $ticket->isGuest() ? 'Guest' : 'Client' }} feedback</div>
                    <div><x-rating :value="$ticket->feedback->rating" /> <span class="text-ink-600">{{ $ticket->feedback->label() }}</span></div>
                    @if ($ticket->feedback->comment)<p class="whitespace-pre-line text-ink-700">{{ $ticket->feedback->comment }}</p>@endif
                    <div class="text-ink-500">{{ $ticket->feedback->created_at->format('M j, Y') }} · <a href="{{ route('admin.tickets.feedback') }}" class="text-brand-600">All feedback</a></div>
                </div>
            @endif

            @if ($otherTickets->isNotEmpty())
                <form method="POST" action="{{ route('admin.tickets.merge', $ticket) }}" class="space-y-2 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    @csrf
                    <div class="font-semibold">Merge into this ticket</div>
                    <p class="text-ink-600">Moves every message of the ticked tickets here and closes them. Their numbers keep working and lead here.</p>
                    @error('tickets')<p class="text-red-700">{{ $message }}</p>@enderror
                    <div class="max-h-48 space-y-1 overflow-y-auto">
                        @foreach ($otherTickets as $other)
                            <label for="merge-{{ $other->id }}" class="flex items-start gap-2"><input id="merge-{{ $other->id }}" type="checkbox" name="tickets[]" value="{{ $other->id }}" class="mt-1"> <span>#{{ $other->id }} {{ $other->subject }} <span class="text-ink-500">· {{ $other->status->label() }}</span></span></label>
                        @endforeach
                    </div>
                    <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Merge</button>
                </form>
            @endif
        </aside>
    </div>
</x-admin-layout>
