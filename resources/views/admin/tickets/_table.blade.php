<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Ticket</th>@unless ($hideClient ?? false)<th class="px-4 py-2">Client</th>@endunless<th class="px-4 py-2">Department</th><th class="px-4 py-2">Priority</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Assigned</th><th class="px-4 py-2">Last reply</th><th class="px-4 py-2">Response due</th></tr></thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($tickets as $ticket)
                <tr>
                    <td class="px-4 py-2">@can('manage-tickets')<a href="{{ route('admin.tickets.show', $ticket) }}" class="text-brand-600">#{{ $ticket->id }} {{ $ticket->subject }}</a>@else#{{ $ticket->id }} {{ $ticket->subject }}@endcan</td>
                    @unless ($hideClient ?? false)<td class="px-4 py-2"><a href="{{ route('admin.clients.show', $ticket->client) }}" class="text-brand-600">{{ $ticket->client->fullName() }}</a></td>@endunless
                    <td class="px-4 py-2">{{ $ticket->department->name }}</td>
                    <td class="px-4 py-2"><x-status-badge :status="$ticket->priority" /></td>
                    <td class="px-4 py-2"><x-status-badge :status="$ticket->status" /></td>
                    <td class="px-4 py-2">{{ $ticket->assignee->name ?? '—' }}</td>
                    <td class="whitespace-nowrap px-4 py-2" title="{{ $ticket->last_reply_at?->format('M j, Y g:ia') }}">{{ $ticket->last_reply_at?->diffForHumans() }}</td>
                    <td class="whitespace-nowrap px-4 py-2">@include('admin.tickets._due')</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
