<x-admin-layout title="Network status">
    @include('admin.support._nav')
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Network status</h1>
        <a href="{{ route('network.index') }}" class="text-sm text-brand-600" target="_blank">View it as a client</a>
        <a href="{{ route('admin.network-issues.create') }}" class="ml-auto rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">New issue</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Current problems and planned maintenance, shown at <code>/network-status</code>. Clients with a service on an affected server see a notice on their portal home page. Servers with <strong>Show on the network status page</strong> ticked are listed there too, with their monitoring status and uptime.</p>

    <h2 class="mb-2 font-semibold">Open and planned</h2>
    @if ($open->isEmpty())
        <p class="mb-6 text-sm text-ink-500">Nothing open.</p>
    @else
        <div class="mb-6">@include('admin.network-issues._table', ['issues' => $open])</div>
    @endif

    <h2 class="mb-2 font-semibold">Resolved</h2>
    @if ($resolved->isEmpty())
        <p class="text-sm text-ink-500">Nothing resolved yet.</p>
    @else
        @include('admin.network-issues._table', ['issues' => $resolved])
        <div class="mt-4">{{ $resolved->links() }}</div>
    @endif
</x-admin-layout>
