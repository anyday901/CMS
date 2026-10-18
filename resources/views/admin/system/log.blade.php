@php
    $styles = [
        'emergency' => 'bg-red-100 text-red-900', 'alert' => 'bg-red-100 text-red-900', 'critical' => 'bg-red-100 text-red-900',
        'error' => 'bg-red-50 text-red-800', 'warning' => 'bg-sun-300/40 text-amber-900', 'notice' => 'bg-brand-100 text-brand-700',
        'info' => 'bg-brand-100 text-brand-700', 'debug' => 'bg-ink-100 text-ink-600',
    ];
@endphp
<x-admin-layout :title="$name">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="font-mono text-2xl font-semibold">{{ $name }}</h1>
        <span class="text-sm text-ink-500">{{ Illuminate\Support\Number::fileSize($size, 1) }}</span>
        <a href="{{ route('admin.system.logs.download', $name) }}" class="ml-auto text-sm text-brand-600">Download</a>
    </div>
    @include('admin.system._nav')
    @if ($truncated)
        <div class="mb-4 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">This file is big, so only its last {{ Illuminate\Support\Number::fileSize($maxBytes, 0) }} is shown. Download it to see everything.</div>
    @endif

    <form method="GET" class="mb-4 flex flex-wrap gap-2 text-sm">
        <label for="log-search" class="sr-only">Search</label>
        <input id="log-search" name="q" value="{{ $search }}" placeholder="Search messages and stack traces" class="w-72 rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        <label for="log-level" class="sr-only">Level</label>
        <select id="log-level" name="level" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            <option value="">Any level</option>
            @foreach ($levels as $value)
                <option value="{{ $value }}" @selected($level === $value)>{{ ucfirst($value) }} ({{ $counts[$value] ?? 0 }})</option>
            @endforeach
        </select>
        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Filter</button>
    </form>

    @if ($entries->isEmpty())
        <p class="text-sm text-ink-500">No entries{{ $level || $search !== '' ? ' that match' : ' in this file' }}.</p>
    @else
        <p class="mb-2 text-sm text-ink-500">Newest first.</p>
        <ul class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
            @foreach ($entries as $entry)
                <li class="px-4 py-3">
                    <div class="flex flex-wrap items-start gap-3">
                        <span class="w-20 shrink-0 rounded-full px-2 py-0.5 text-center text-xs font-medium {{ $styles[$entry['level']] ?? 'bg-ink-100 text-ink-600' }}">{{ ucfirst($entry['level']) }}</span>
                        <span class="shrink-0 font-mono text-xs text-ink-500">{{ $entry['time'] }}@if ($entry['environment']) · {{ $entry['environment'] }}@endif</span>
                        <p class="min-w-0 flex-1 break-words font-mono text-xs">{{ $entry['message'] }}</p>
                    </div>
                    @if ($entry['details'] !== '')
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-brand-600">Details</summary>
                            <pre class="mt-2 max-h-96 overflow-auto rounded bg-ink-50 p-3 text-xs">{{ $entry['details'] }}</pre>
                        </details>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="mt-4">{{ $entries->links() }}</div>
    @endif
</x-admin-layout>
