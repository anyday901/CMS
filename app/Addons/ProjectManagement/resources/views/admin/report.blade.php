@php use App\Addons\ProjectManagement\Duration; use App\Support\Money; @endphp
<x-admin-layout title="Time report">
    <p class="mb-2 text-sm"><a href="{{ route('admin.projects.index') }}" class="text-brand-600">Projects</a></p>
    <h1 class="mb-6 text-2xl font-semibold">Time report</h1>

    <form method="GET" action="{{ route('admin.projects.report') }}" class="mb-6 flex flex-wrap items-end gap-3 text-sm">
        <label class="block">From
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="mt-1 block rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        </label>
        <label class="block">To
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="mt-1 block rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        </label>
        <label class="block">Who
            <select name="user" class="mt-1 block rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                <option value="">Everyone</option>
                @foreach ($staff as $member)
                    <option value="{{ $member->id }}" @selected((string) $user === (string) $member->id)>{{ $member->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Show</button>
    </form>

    <div class="mb-8 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div><div class="text-ink-500">Total logged</div><div class="text-xl font-semibold">{{ Duration::format($total) }}</div></div>
        <div><div class="text-ink-500">Billable</div><div class="text-xl font-semibold">{{ Duration::format($billable) }}</div></div>
        <div><div class="text-ink-500">Not billable</div><div class="text-xl font-semibold">{{ Duration::format($total - $billable) }}</div></div>
    </div>

    @if ($total === 0)
        <p class="rounded-lg border border-dashed border-ink-300 bg-white p-6 text-center text-sm text-ink-500">No time logged between these dates.</p>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white lg:col-span-2">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-ink-500">
                        <tr><th class="px-4 py-2">Project</th><th class="px-4 py-2">Client</th><th class="px-4 py-2 text-right">Time</th><th class="px-4 py-2 text-right">Billable</th><th class="px-4 py-2 text-right">Worth</th></tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($byProject as $row)
                            <tr>
                                <td class="px-4 py-2"><a href="{{ route('admin.projects.show', $row['project']) }}" class="text-brand-600">{{ $row['project']->title }}</a></td>
                                <td class="px-4 py-2">{{ $row['project']->client?->fullName() ?? 'Internal' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ Duration::format($row['minutes']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ Duration::format($row['billable']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ Money::format($row['amount'], $row['project']->currency()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="h-fit overflow-x-auto rounded-lg border border-ink-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Staff</th><th class="px-4 py-2 text-right">Time</th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($byStaff as $name => $minutes)
                            <tr><td class="px-4 py-2">{{ $name }}</td><td class="whitespace-nowrap px-4 py-2 text-right">{{ Duration::format($minutes) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-admin-layout>
