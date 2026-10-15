@php
    use App\Reports\Cell;
    use App\Reports\Report;
    use App\Reports\ReportTable;
    $today = now()->startOfDay();
    $presets = [
        'This month' => [$today->copy()->startOfMonth(), $today],
        'Last month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
        'This year' => [$today->copy()->startOfYear(), $today],
        'Last year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
        'Last 12 months' => [$today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today],
    ];
    $shows = fn (string $filter) => in_array($filter, $report->filters(), true);
    $numeric = [ReportTable::MONEY, ReportTable::NUMBER, ReportTable::PERCENT, ReportTable::DURATION];
@endphp
<x-admin-layout :title="$report->title()">
    <div class="mb-1 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.reports.index') }}" class="text-sm text-brand-600">Reports</a>
        <span class="text-sm text-ink-400">/ {{ $report->group() }}</span>
    </div>
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $report->title() }}</h1>
        <a href="{{ route('admin.reports.export', [$report->key()] + $filters->query()) }}" class="ml-auto rounded-full px-4 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-brand-500 hover:bg-brand-50">Export to CSV</a>
    </div>
    <p class="mb-5 max-w-3xl text-sm text-ink-600">{{ $report->description() }}</p>

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @if ($shows(Report::DATES))
            <label class="flex flex-col gap-1">From
                <input type="date" name="from" value="{{ $filters->from->toDateString() }}" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            </label>
            <label class="flex flex-col gap-1">To
                <input type="date" name="to" value="{{ $filters->to->toDateString() }}" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            </label>
        @endif
        @if ($shows(Report::CURRENCY) && count($currencies) > 1)
            <label class="flex flex-col gap-1">Currency
                <select name="currency" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                    @foreach ($currencies as $code)
                        <option value="{{ $code }}" @selected($filters->currency === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if ($shows(Report::CLIENT))
            <label class="flex flex-col gap-1">Client number or email
                <input type="text" name="client" value="{{ request('client') }}" placeholder="e.g. 1042 or ada@example.com" class="w-64 rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            </label>
        @endif
        @foreach ($report->options() as $name => [$label, $choices])
            <label class="flex flex-col gap-1">{{ $label }}
                <select name="{{ $name }}" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                    @foreach ($choices as $value => $choice)
                        <option value="{{ $value }}" @selected($filters->option($name) === (string) $value)>{{ $choice }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
        <button class="rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">Show</button>
        @if ($shows(Report::DATES))
            <div class="flex w-full flex-wrap gap-2 text-xs">
                @foreach ($presets as $label => [$from, $to])
                    <a href="{{ route('admin.reports.show', [$report->key()] + array_merge($filters->query(), ['from' => $from->toDateString(), 'to' => $to->toDateString()])) }}" class="rounded-full bg-ink-50 px-3 py-1 text-ink-700 ring-1 ring-ink-200 hover:bg-ink-100">{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </form>

    @if ($shows(Report::CLIENT) && $filters->client)
        <p class="mb-4 text-sm">Statement for <a href="{{ route('admin.clients.show', $filters->client) }}" class="font-medium text-brand-600">{{ App\Reports\Invoices\ClientStatement::label($filters->client) }}</a>, in {{ $filters->client->currency }}.</p>
    @elseif ($shows(Report::CLIENT) && filled(request('client')))
        <p class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">No client has that number or email address.</p>
    @endif

    @foreach ($result->tables as $table)
        <section class="mb-6">
            @if ($table->title && count($result->tables) > 1)
                <h2 class="mb-2 font-display text-base font-bold">{{ $table->title }}</h2>
            @endif
            @if ($table->rows === [])
                <p class="rounded-lg border border-ink-200 bg-white px-4 py-3 text-sm text-ink-500">{{ $table->empty }}</p>
            @else
                @php
                    $max = $table->chartMax();
                    $pad = count($table->columns) > 8 ? 'px-2' : 'px-3';
                @endphp
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full {{ count($table->columns) > 8 ? 'text-xs' : 'text-sm' }}">
                        <thead class="bg-ink-50 text-ink-500">
                            <tr>
                                @foreach ($table->columns as $key => [$label, $type])
                                    <th scope="col" class="whitespace-nowrap {{ $pad }} py-2 {{ in_array($type, $numeric, true) ? 'text-right' : 'text-left' }}">{{ $label }}</th>
                                @endforeach
                                @if ($table->chart)
                                    <th scope="col" class="w-40 px-3 py-2"><span class="sr-only">Chart</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($table->rows as $row)
                                <tr class="align-top">
                                    @foreach ($table->columns as $key => [$label, $type])
                                        @php $text = Cell::display($row[$key] ?? null, $type, $table->currency); @endphp
                                        <td class="{{ $pad }} py-1.5 {{ in_array($type, $numeric, true) ? 'whitespace-nowrap text-right tabular-nums' : ($key === 'comment' ? 'max-w-md whitespace-pre-line' : '') }}">
                                            @if (isset($row['_links'][$key]))
                                                <a href="{{ $row['_links'][$key] }}" class="text-brand-600 hover:underline">{{ $text }}</a>
                                            @else
                                                {{ $text }}
                                            @endif
                                        </td>
                                    @endforeach
                                    @if ($table->chart)
                                        <td class="px-3 py-1.5">
                                            <div class="h-2.5 rounded-full bg-ink-100" aria-hidden="true"><div class="h-2.5 rounded-full {{ ($row[$table->chart] ?? 0) < 0 ? 'bg-red-400' : 'bg-brand-500' }}" style="width: {{ $max > 0 ? round(abs($row[$table->chart] ?? 0) / $max * 100) : 0 }}%"></div></div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($table->totals)
                            <tfoot class="border-t-2 border-ink-200 bg-ink-50 font-semibold">
                                <tr>
                                    @foreach ($table->columns as $key => [$label, $type])
                                        <td class="whitespace-nowrap {{ $pad }} py-2 {{ in_array($type, $numeric, true) ? 'text-right tabular-nums' : '' }}">{{ array_key_exists($key, $table->totals) && $table->totals[$key] !== null ? Cell::display($table->totals[$key], $type, $table->currency) : '' }}</td>
                                    @endforeach
                                    @if ($table->chart)
                                        <td></td>
                                    @endif
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @endif
        </section>
    @endforeach

    @if ($result->notes)
        <div class="max-w-3xl space-y-2 text-sm text-ink-600">
            @foreach ($result->notes as $note)
                <p>{{ $note }}</p>
            @endforeach
        </div>
    @endif
</x-admin-layout>
