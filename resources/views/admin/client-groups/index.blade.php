<x-admin-layout title="Client groups">
    <p class="mb-2 text-sm"><a href="{{ route('admin.settings.edit') }}" class="text-brand-600">Settings</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Client groups</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Sort clients into groups, such as resellers or VIPs. A client's group shows as a coloured label on their page and in the clients list, and the clients search can filter by it. Put a client in a group on their edit page.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($groups->isEmpty())
                <p class="text-sm text-ink-500">No groups yet.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Group</th><th class="px-4 py-2">Clients</th><th class="px-4 py-2"></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($groups as $clientGroup)
                                <tr>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('admin.client-groups.edit', $clientGroup) }}"><x-client-group-badge :group="$clientGroup" /></a>
                                        @if ($clientGroup->description)<span class="ml-2 text-ink-500">{{ $clientGroup->description }}</span>@endif
                                    </td>
                                    <td class="px-4 py-2"><a href="{{ route('admin.clients.index', ['group' => $clientGroup->id, 'show_closed' => 1]) }}" class="text-brand-600">{{ $clientGroup->clients_count }}</a></td>
                                    <td class="px-4 py-2 text-right">
                                        <form method="POST" action="{{ route('admin.client-groups.destroy', $clientGroup) }}" onsubmit="return confirm('Delete this group? Its clients stay, without a group.')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.client-groups.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a group</h2>
            @include('admin.client-groups.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add group</button>
        </form>
    </div>
</x-admin-layout>
