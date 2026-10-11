<x-portal-layout title="Support">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Support</h1>
        <a href="{{ route('portal.tickets.create') }}" class="ml-auto rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Open a ticket</a>
    </div>
    @if ($tickets->isEmpty())
        <p class="text-sm text-ink-500">You haven't opened any tickets. If you need help, open one and we'll get back to you.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Ticket</th><th class="px-4 py-2">Department</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Last reply</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($tickets as $ticket)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('portal.tickets.show', $ticket) }}" class="text-brand-600">#{{ $ticket->id }} {{ $ticket->subject }}</a></td>
                            <td class="px-4 py-2">{{ $ticket->department->name }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$ticket->status" /></td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $ticket->last_reply_at?->format('M j, Y g:ia') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
</x-portal-layout>
