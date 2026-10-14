<x-portal-layout title="Network status" :store="true">
    @include('help._nav')
    <h1 class="mb-4 text-2xl font-semibold">Network status</h1>

    @if ($current->isEmpty())
        <div class="mb-6 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">All systems are working normally.</div>
    @else
        <h2 class="mb-3 text-lg font-semibold">Current issues</h2>
        <div class="mb-8 space-y-3">
            @foreach ($current as $issue)
                @include('help.network._issue')
            @endforeach
        </div>
    @endif

    @if ($servers->isNotEmpty())
        <h2 class="mb-3 text-lg font-semibold">Servers</h2>
        <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Server</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Uptime (30 days)</th><th class="px-4 py-2">Last checked</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($servers as $server)
                        <tr>
                            <td class="px-4 py-2 font-medium">{{ $server->name }}</td>
                            <td class="px-4 py-2">@if ($server->status)<x-status-badge :status="$server->status" />@else<span class="text-ink-500">Not checked yet</span>@endif</td>
                            <td class="px-4 py-2">{{ ($uptime = $server->uptime()) === null ? '—' : $uptime.'%' }}</td>
                            <td class="px-4 py-2 text-ink-500">{{ $server->status_checked_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Planned maintenance</h2>
    @if ($upcoming->isEmpty())
        <p class="mb-8 text-sm text-ink-500">Nothing planned.</p>
    @else
        <div class="mb-8 space-y-3">
            @foreach ($upcoming as $issue)
                @include('help.network._issue')
            @endforeach
        </div>
    @endif

    @if ($resolved->isNotEmpty())
        <h2 class="mb-3 text-lg font-semibold">Resolved in the last 30 days</h2>
        <div class="space-y-3">
            @foreach ($resolved as $issue)
                @include('help.network._issue')
            @endforeach
        </div>
    @endif
</x-portal-layout>
