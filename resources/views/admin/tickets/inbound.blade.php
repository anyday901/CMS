<x-admin-layout title="Email and spam log">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Email and spam log</h1>
        <a href="{{ route('admin.tickets.index') }}" class="ml-auto text-sm text-brand-600">Back to tickets</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">
        Every email piped in for tickets, and contact form messages caught as spam, with what became of them. {{ $spamCount }} caught as spam in the last 7 days.
        @can('manage-settings')Change what counts as spam under <a href="{{ route('admin.settings.edit') }}#spam" class="text-brand-600">Settings</a>.@endcan
        The log keeps {{ App\Models\InboundEmail::KEEP_DAYS }} days.
    </p>

    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <a href="{{ route('admin.tickets.inbound', array_filter(['q' => $search ?: null])) }}" class="{{ $outcome === null ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100' }}">All</a>
        @foreach (App\Models\InboundEmail::OUTCOMES as $value => $label)
            <a href="{{ route('admin.tickets.inbound', array_filter(['outcome' => $value, 'q' => $search ?: null])) }}" class="{{ $outcome === $value ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100' }}">{{ $label }}</a>
        @endforeach
        @if ($outcome)<input type="hidden" name="outcome" value="{{ $outcome }}">@endif
        <label for="inbound-search" class="sr-only">Search by sender or subject</label>
        <input id="inbound-search" name="q" value="{{ $search }}" placeholder="Sender or subject" class="ml-auto rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        <button class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Search</button>
    </form>

    @if ($emails->isEmpty())
        <p class="text-sm text-ink-500">Nothing here yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Received</th><th class="px-4 py-2">From</th><th class="px-4 py-2">Subject</th><th class="px-4 py-2">Outcome</th><th class="px-4 py-2">Why</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($emails as $email)
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-4 py-2">{{ $email->created_at->format('M j, Y g:ia') }}</td>
                            <td class="px-4 py-2">{{ $email->from ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $email->subject ?? '(No subject)' }}</td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-red-100 text-red-800' => $email->outcome === App\Models\InboundEmail::SPAM, 'bg-ink-100 text-ink-700' => $email->outcome === 'ignored', 'bg-green-100 text-green-800' => in_array($email->outcome, ['opened', 'replied'], true)])>{{ $email->outcomeLabel() }}</span>
                                @if ($email->ticket_id)<a href="{{ route('admin.tickets.show', $email->ticket_id) }}" class="ml-1 text-brand-600">#{{ $email->ticket_id }}</a>@endif
                            </td>
                            <td class="px-4 py-2 text-ink-600">{{ $email->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $emails->links() }}</div>
    @endif
</x-admin-layout>
