<x-admin-layout title="Reports">
    <h1 class="mb-2 text-2xl font-semibold">Reports</h1>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">Pick a report, then choose its dates and currency. Every report downloads as a CSV file for a spreadsheet. Clients imported from WHMCS bring their history with them, so reports cover the years before the switch too.</p>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach ($groups as $group => $reports)
            <section class="rounded-lg border border-ink-200 bg-white p-4">
                <h2 class="mb-3 font-display text-base font-bold">{{ $group }}</h2>
                <ul class="space-y-3 text-sm">
                    @foreach ($reports as $report)
                        <li>
                            <a href="{{ route('admin.reports.show', $report->key()) }}" class="font-medium text-brand-600 hover:underline">{{ $report->title() }}</a>
                            <p class="text-ink-600">{{ $report->description() }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-admin-layout>
