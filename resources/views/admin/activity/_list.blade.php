<div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Who</th><th class="px-4 py-2">What</th>@if ($showClient ?? true)<th class="px-4 py-2">Client</th>@endif<th class="px-4 py-2">IP</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap px-4 py-2" title="{{ $entry->created_at->toDayDateTimeString() }}">{{ $entry->created_at->format('M j, Y g:ia') }}</td>
                    <td class="whitespace-nowrap px-4 py-2">{{ $entry->actor_name }} <span class="text-gray-400">{{ $entry->actor_type }}</span></td>
                    <td class="px-4 py-2">{{ $entry->description }}</td>
                    @if ($showClient ?? true)
                        <td class="px-4 py-2">@if ($entry->client)<a href="{{ route('admin.clients.show', $entry->client) }}" class="text-indigo-600">{{ $entry->client->fullName() }}</a>@else — @endif</td>
                    @endif
                    <td class="px-4 py-2 text-gray-500">{{ $entry->ip_address ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
