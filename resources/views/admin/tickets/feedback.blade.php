<x-admin-layout title="Ticket feedback">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Ticket feedback</h1>
        <a href="{{ route('admin.tickets.index') }}" class="ml-auto text-sm text-brand-600">Back to tickets</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">Clients rate the support from 1 to 5 stars when a ticket is closed, in departments that ask for feedback. Turn it on for a department under <a href="{{ route('admin.departments.index') }}" class="text-brand-600">Departments</a>.</p>

    <form method="GET" class="mb-6 flex flex-wrap items-center gap-2 text-sm">
        <label class="flex items-center gap-1.5">Period
            <select name="period" onchange="this.form.submit()" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                @foreach ($periods as $value => $label)
                    <option value="{{ $value }}" @selected($period === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-1.5">Department
            <select name="department" onchange="this.form.submit()" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                <option value="">All departments</option>
                @foreach ($departments as $item)
                    <option value="{{ $item->id }}" @selected($department === $item->id)>{{ $item->name }}</option>
                @endforeach
            </select>
        </label>
    </form>

    @if ($count === 0)
        <p class="text-sm text-ink-500">No ratings in this period yet.</p>
    @else
        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <div class="text-ink-500">Average rating</div>
                <div class="mt-1 text-3xl font-semibold">{{ number_format($average, 2) }}<span class="text-base font-normal text-ink-500"> / {{ App\Models\TicketFeedback::MAX_RATING }}</span></div>
                <div class="mt-1 text-ink-600">from {{ $count }} {{ Str::plural('rating', $count) }}</div>
            </div>
            <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <div class="mb-2 text-ink-500">Ratings</div>
                @foreach ($distribution as $rating => $total)
                    <div class="flex items-center gap-2">
                        <span class="w-4 text-right">{{ $rating }}</span>
                        <div class="h-2 flex-1 rounded-full bg-ink-100"><div class="h-2 rounded-full bg-amber-400" style="width: {{ $count ? round($total / $count * 100) : 0 }}%"></div></div>
                        <span class="w-8 text-right text-ink-600">{{ $total }}</span>
                    </div>
                @endforeach
            </div>
            <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <div class="mb-2 text-ink-500">By staff who replied</div>
                @forelse ($staff as $row)
                    <div class="flex justify-between gap-2"><span>{{ $row['name'] }}</span><span class="text-ink-600">{{ number_format($row['average'], 2) }} · {{ $row['count'] }} {{ Str::plural('ticket', $row['count']) }}</span></div>
                @empty
                    <p class="text-ink-500">No staff replies on the rated tickets.</p>
                @endforelse
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Rating</th><th class="px-4 py-2">Ticket</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Comment</th><th class="px-4 py-2">Rated</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($feedback as $item)
                        <tr class="align-top">
                            <td class="px-4 py-2"><x-rating :value="$item->rating" /></td>
                            <td class="px-4 py-2"><a href="{{ route('admin.tickets.show', $item->ticket) }}" class="text-brand-600">#{{ $item->ticket_id }} {{ $item->ticket->subject }}</a><div class="text-ink-500">{{ $item->ticket->department->name }}</div></td>
                            <td class="px-4 py-2">@include('admin.tickets._requester', ['ticket' => $item->ticket])</td>
                            <td class="max-w-md whitespace-pre-line px-4 py-2">{{ $item->comment ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $item->created_at->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $feedback->links() }}</div>
    @endif
</x-admin-layout>
