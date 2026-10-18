@php
    use App\Billing\AccountStatement;
    use App\Support\Money;
    $client = $statement->client;
    $currency = $client->currency;
    $date = fn (string $value) => Carbon\CarbonImmutable::parse($value)->translatedFormat('M j, Y');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Account statement') }}</title>
<style>
@include('pdf._harbor')
</style>
</head>
<body>
    <table class="header" role="presentation">
        <tr>
            <td>
                @if ($logo = App\Support\Branding::logoDataUri())<img src="{{ $logo }}" alt="{{ $company['name'] }}" style="max-height: 48px; max-width: 220px; margin-bottom: 6px;"><br>@endif
                <h1>{{ $company['name'] }}</h1>
                @if ($company['address'])<div>{!! nl2br(e(str_replace('\n', "\n", $company['address']))) !!}</div>@endif
                @if ($company['email'])<div>{{ $company['email'] }}</div>@endif
            </td>
            <td style="text-align: right;">
                <h1>{{ __('Account statement') }}</h1>
                <div style="margin-top: 6px;">{{ $date($statement->from->toDateString()) }} – {{ $date($statement->to->toDateString()) }}</div>
                <div>{{ __('Currency: :code', ['code' => $currency]) }}</div>
            </td>
        </tr>
    </table>

    <div style="margin-top: 24px;">
        <div class="muted">{{ __('Account') }}</div>
        <div><strong>{{ $client->company ?: $client->fullName() }}</strong></div>
        @if ($client->company)<div>{{ $client->fullName() }}</div>@endif
        @foreach ([$client->address1, $client->address2, collect([$client->city, $client->state, $client->postcode])->filter()->join(', '), $client->country] as $line)
            @if ($line)<div>{{ $line }}</div>@endif
        @endforeach
        <div>{{ $client->email }}</div>
    </div>

    <table class="items">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Details') }}</th><th class="num">{{ __('Charged') }}</th><th class="num">{{ __('Paid') }}</th><th class="num">{{ __('Balance') }}</th></tr></thead>
        <tbody>
            <tr><td>{{ $date($statement->from->toDateString()) }}</td><td colspan="5">{{ __('Opening balance') }}</td><td class="num">{{ Money::format($statement->opening, $currency) }}</td></tr>
            @foreach ($statement->lines as $line)
                <tr>
                    <td style="white-space: nowrap;">{{ $date($line['date']) }}</td>
                    <td>{{ AccountStatement::typeLabel($line) }}</td>
                    <td>{{ $line['invoice_id'] ? '#'.$line['invoice_number'] : '' }}</td>
                    <td>{{ AccountStatement::details($line) }}</td>
                    <td class="num">{{ $line['debit'] ? Money::format($line['debit'], $currency) : '' }}</td>
                    <td class="num">{{ $line['credit'] ? Money::format($line['credit'], $currency) : '' }}</td>
                    <td class="num">{{ Money::format($line['balance'], $currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" role="presentation" style="margin-top: 8px;">
        <tr><td class="label">{{ __('Charged') }}</td><td class="num" style="width: 120px;">{{ Money::format($statement->charged(), $currency) }}</td></tr>
        <tr><td class="label">{{ __('Paid') }}</td><td class="num">{{ Money::format($statement->paid(), $currency) }}</td></tr>
        <tr class="grand"><td class="label">{{ __('Closing balance on :date', ['date' => $date($statement->to->toDateString())]) }}</td><td class="num">{{ Money::format($statement->closing, $currency) }}</td></tr>
    </table>
    <p class="note" style="margin-top: 8px;">{{ __('Balance is what you owed after each line; below zero, you had paid ahead. Payments from your credit balance show as Credit applied.') }}</p>

    <div style="margin-top: 24px;">
        <div class="muted">{{ __('Credit balance') }}</div>
        <div>{{ __(':opening on :from, :closing at the end of :to.', ['opening' => Money::format($statement->creditOpening, $currency), 'from' => $date($statement->from->toDateString()), 'closing' => Money::format($statement->creditClosing, $currency), 'to' => $date($statement->to->toDateString())]) }}</div>
    </div>
    @if ($statement->creditEntries !== [])
        <table class="items" style="margin-top: 8px;">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Details') }}</th><th class="num">{{ __('Amount') }}</th><th class="num">{{ __('Credit balance') }}</th></tr></thead>
            <tbody>
                @foreach ($statement->creditEntries as $entry)
                    <tr><td style="white-space: nowrap;">{{ $date($entry['date']) }}</td><td>{{ $entry['description'] }}</td><td class="num">{{ Money::format($entry['amount'], $currency) }}</td><td class="num">{{ Money::format($entry['balance'], $currency) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
