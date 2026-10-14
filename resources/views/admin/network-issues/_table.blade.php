<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Issue</th><th class="px-4 py-2">Affects</th><th class="px-4 py-2">Priority</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Starts</th><th class="px-4 py-2">Ends</th><th class="px-4 py-2"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($issues as $issue)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route('admin.network-issues.edit', $issue) }}" class="text-brand-600">{{ $issue->title }}</a></td>
                    <td class="px-4 py-2">{{ $issue->affects() }}</td>
                    <td class="px-4 py-2">{{ $issue->priorityLabel() }}</td>
                    <td class="px-4 py-2">{{ $issue->statusLabel() }}</td>
                    <td class="whitespace-nowrap px-4 py-2">{{ $issue->starts_at->format('M j, g:ia') }}</td>
                    <td class="whitespace-nowrap px-4 py-2">{{ $issue->ends_at?->format('M j, g:ia') ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">
                        @if ($issue->status !== 'resolved')
                            <form method="POST" action="{{ route('admin.network-issues.resolve', $issue) }}">@csrf
                                <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Resolve</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
