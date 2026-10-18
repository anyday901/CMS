@php
    use App\Billing\AccountStatement;
    use App\Support\Money;
    $currency = $statement->client->currency;
    $range = ['from' => $statement->from->toDateString(), 'to' => $statement->to->toDateString()];
    $today = now()->startOfDay();
    $presets = [
        __('This year') => [$today->copy()->startOfYear(), $today],
        __('Last year') => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
        __('Last 12 months') => [$today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today],
    ];
@endphp
<x-portal-layout :title="__('Account statement')">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ __('Account statement') }}</h1>
        <a href="{{ route('portal.invoices.index') }}" class="ml-auto text-sm text-brand-600">{{ __('Invoices') }}</a>
        <a href="{{ route('portal.statement.pdf', $range) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Download PDF') }}</a>
    </div>
    <p class="mb-5 max-w-3xl text-sm text-ink-600">{{ __('Your invoices, payments and refunds between two dates, with what you owed after each, and the changes to your credit balance.') }}</p>

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        <label class="flex flex-col gap-1">{{ __('From') }}
            <input type="date" name="from" value="{{ $range['from'] }}" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        </label>
        <label class="flex flex-col gap-1">{{ __('To') }}
            <input type="date" name="to" value="{{ $range['to'] }}" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        </label>
        <button class="rounded-md bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">{{ __('Show') }}</button>
        <div class="flex w-full flex-wrap gap-2 text-xs">
            @foreach ($presets as $label => [$from, $to])
                <a href="{{ route('portal.statement.show', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="rounded-full bg-ink-50 px-3 py-1 text-ink-700 ring-1 ring-ink-200 hover:bg-ink-100">{{ $label }}</a>
            @endforeach
        </div>
    </form>

    <div class="mb-6 grid gap-2 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-4">
        <div><div class="text-ink-500">{{ __('Opening balance on :date', ['date' => $statement->from->translatedFormat('M j, Y')]) }}</div><div class="font-semibold">{{ Money::format($statement->opening, $currency) }}</div></div>
        <div><div class="text-ink-500">{{ __('Charged') }}</div><div class="font-semibold">{{ Money::format($statement->charged(), $currency) }}</div></div>
        <div><div class="text-ink-500">{{ __('Paid') }}</div><div class="font-semibold">{{ Money::format($statement->paid(), $currency) }}</div></div>
        <div><div class="text-ink-500">{{ __('Closing balance on :date', ['date' => $statement->to->translatedFormat('M j, Y')]) }}</div><div class="font-semibold">{{ Money::format($statement->closing, $currency) }}</div></div>
    </div>

    @if ($statement->lines === [])
        <p class="mb-6 rounded-lg border border-ink-200 bg-white px-4 py-3 text-sm text-ink-500">{{ __('No invoices or payments in these dates.') }}</p>
    @else
        <div class="mb-6 overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500">
                    <tr><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2">{{ __('Type') }}</th><th class="px-4 py-2">{{ __('Invoice') }}</th><th class="px-4 py-2">{{ __('Details') }}</th><th class="px-4 py-2 text-right">{{ __('Charged') }}</th><th class="px-4 py-2 text-right">{{ __('Paid') }}</th><th class="px-4 py-2 text-right">{{ __('Balance') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($statement->lines as $line)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ Carbon\CarbonImmutable::parse($line['date'])->translatedFormat('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ AccountStatement::typeLabel($line) }}</td>
                            <td class="px-4 py-2">@if ($line['invoice_id'])<a href="{{ route('portal.invoices.show', $line['invoice_id']) }}" class="text-brand-600">#{{ $line['invoice_number'] }}</a>@endif</td>
                            <td class="px-4 py-2">{{ AccountStatement::details($line) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $line['debit'] ? Money::format($line['debit'], $currency) : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $line['credit'] ? Money::format($line['credit'], $currency) : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ Money::format($line['balance'], $currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="mb-8 max-w-3xl text-sm text-ink-600">{{ __('Balance is what you owed after each line; below zero, you had paid ahead. Payments from your credit balance show as Credit applied.') }}</p>

    <h2 class="mb-2 text-lg font-semibold">{{ __('Credit balance') }}</h2>
    <p class="mb-3 text-sm text-ink-600">{{ __(':opening on :from, :closing at the end of :to.', ['opening' => Money::format($statement->creditOpening, $currency), 'from' => $statement->from->translatedFormat('M j, Y'), 'closing' => Money::format($statement->creditClosing, $currency), 'to' => $statement->to->translatedFormat('M j, Y')]) }}</p>
    @if ($statement->creditEntries === [])
        <p class="rounded-lg border border-ink-200 bg-white px-4 py-3 text-sm text-ink-500">{{ __('No changes to your credit balance in these dates.') }}</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500">
                    <tr><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2">{{ __('Details') }}</th><th class="px-4 py-2 text-right">{{ __('Amount') }}</th><th class="px-4 py-2 text-right">{{ __('Credit balance') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($statement->creditEntries as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ Carbon\CarbonImmutable::parse($entry['date'])->translatedFormat('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ $entry['description'] }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ Money::format($entry['amount'], $currency) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ Money::format($entry['balance'], $currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-portal-layout>
