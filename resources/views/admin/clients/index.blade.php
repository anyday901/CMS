<x-admin-layout title="Clients">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Clients</h1>
        <form class="ml-auto"><input name="q" value="{{ $search }}" placeholder="Search name, company or email" class="w-64 rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300"></form>
        <a href="{{ route('admin.clients.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Add client</a>
    </div>
    @if ($clients->isEmpty())
        <p class="text-sm text-gray-500">No clients found.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Company</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Services</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($clients as $client)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $client) }}" class="text-indigo-600">{{ $client->fullName() }}</a></td>
                            <td class="px-4 py-2">{{ $client->company }}</td>
                            <td class="px-4 py-2">{{ $client->email }}</td>
                            <td class="px-4 py-2">{{ $client->services_count }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$client->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $clients->links() }}</div>
    @endif
</x-admin-layout>
