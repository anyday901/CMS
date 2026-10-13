<x-admin-layout title="Servers">
    <p class="mb-2 text-sm"><a href="{{ route('admin.products.index') }}" class="text-brand-600">Products</a></p>
    <div class="mb-2 flex items-center">
        <h1 class="text-2xl font-semibold">Servers</h1>
        <a href="{{ route('admin.servers.create') }}" class="ml-auto rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add server</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">The machines the {{ $modules->map->label()->join(', ', ' and ') }} modules set services up on. A product using one of those modules puts each new service on a server from its server group, or on any active server of the module when it has no group. Full and inactive servers get no new accounts.</p>

    @if ($servers->isEmpty())
        <p class="mb-10 text-sm text-ink-500">No servers yet.</p>
    @else
        <div class="mb-10 overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Module</th><th class="px-4 py-2">Accounts</th><th class="px-4 py-2">Groups</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($servers as $server)
                        @php $percent = min(100, (int) round($server->usage() * 100)); @endphp
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.servers.edit', $server) }}" class="font-medium text-brand-600">{{ $server->name }}</a>
                                <div class="text-ink-500">{{ $server->hostname }}{{ $server->ip_address ? " ({$server->ip_address})" : '' }}</div></td>
                            <td class="px-4 py-2">{{ $modules->get($server->module)?->label() ?? $server->module }}</td>
                            <td class="px-4 py-2">
                                <div>{{ number_format($server->account_count) }} of {{ number_format($server->max_accounts) }}</div>
                                <div class="mt-1 h-1.5 w-28 overflow-hidden rounded-full bg-ink-100" aria-hidden="true">
                                    <div class="h-full rounded-full {{ $percent >= 90 ? 'bg-orange-500' : 'bg-brand-500' }}" style="width: {{ $percent }}%"></div>
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ $server->groups->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$server->active ? 'active' : 'inactive'" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mb-2 flex items-center">
        <h2 class="text-lg font-semibold">Server groups</h2>
        <a href="{{ route('admin.server-groups.create') }}" class="ml-auto rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add group</a>
    </div>
    <p class="mb-4 max-w-3xl text-sm text-ink-600">Choose a product's group on the product page. <strong>Least used</strong> spreads new accounts out, each on the server with the lowest share of its maximum in use. <strong>Fill up</strong> puts them on the fullest server that still has room, so one server fills before the next is used.</p>
    @if ($groups->isEmpty())
        <p class="text-sm text-ink-500">No server groups yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Fill type</th><th class="px-4 py-2">Servers</th><th class="px-4 py-2">Products</th><th class="px-4 py-2"></th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($groups as $serverGroup)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.server-groups.edit', $serverGroup) }}" class="font-medium text-brand-600">{{ $serverGroup->name }}</a></td>
                            <td class="px-4 py-2">{{ $serverGroup->fillLabel() }}</td>
                            <td class="px-4 py-2">{{ $serverGroup->servers->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="px-4 py-2">{{ $serverGroup->products_count }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('admin.server-groups.destroy', $serverGroup) }}" onsubmit="return confirm('Delete this group? Its products will put new services on any server of their module.')">
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
</x-admin-layout>
