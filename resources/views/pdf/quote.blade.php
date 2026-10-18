@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Quote #:number', ['number' => $quote->number]) }}</title>
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
                @if ($company['tax_id'])<div>{{ __('Tax ID:') }} {{ $company['tax_id'] }}</div>@endif
            </td>
            <td style="text-align: right;">
                <h1>{{ __('Quote #:number', ['number' => $quote->number]) }}</h1>
                <div style="margin: 6px 0;">{{ $quote->subject }}</div>
                <div>{{ __('Date: :date', ['date' => $quote->issue_date->translatedFormat('M j, Y')]) }}</div>
                <div>{{ __('Valid until: :date', ['date' => $quote->valid_until->translatedFormat('M j, Y')]) }}</div>
                @if ($quote->accepted_at)<div>{{ __('Accepted: :date', ['date' => $quote->accepted_at->translatedFormat('M j, Y')]) }}</div>@endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 24px;">
        <div class="muted">{{ __('Prepared for') }}</div>
        @php $client = $quote->client; @endphp
        <div><strong>{{ $client->company ?: $client->fullName() }}</strong></div>
        @if ($client->company)<div>{{ $client->fullName() }}</div>@endif
        @foreach ([$client->address1, $client->address2, collect([$client->city, $client->state, $client->postcode])->filter()->join(', '), $client->country] as $line)
            @if ($line)<div>{{ $line }}</div>@endif
        @endforeach
        <div>{{ $client->email }}</div>
        @if ($quote->client_tax_id)<div>{{ __('Tax ID:') }} {{ $quote->client_tax_id }}</div>@endif
    </div>

    @if ($quote->notes)
        <div style="margin-top: 24px;">{!! nl2br(e($quote->notes)) !!}</div>
    @endif

    <table class="items">
        <thead><tr><th>{{ __('Description') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr><td>{{ $item->description }}</td><td class="num">{{ Money::format($item->amount, $quote->currency) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" role="presentation" style="margin-top: 8px;">
        @if ($quote->tax && ! $quote->tax_inclusive)
            <tr><td class="label">{{ __('Subtotal') }}</td><td class="num" style="width: 120px;">{{ Money::format($quote->subtotal, $quote->currency) }}</td></tr>
            @foreach ($quote->taxLines() as [$taxLabel, $taxAmount])<tr><td class="label">{{ $taxLabel }}</td><td class="num">{{ Money::format($taxAmount, $quote->currency) }}</td></tr>@endforeach
        @endif
        <tr class="grand"><td class="label">{{ __('Total') }}</td><td class="num" style="width: 120px;">{{ Money::format($quote->total, $quote->currency) }}</td></tr>
        @if ($quote->tax && $quote->tax_inclusive)
            @foreach ($quote->taxLines() as [$taxLabel, $taxAmount])<tr><td class="label">{{ __('Includes :tax', ['tax' => $taxLabel]) }}</td><td class="num">{{ Money::format($taxAmount, $quote->currency) }}</td></tr>@endforeach
        @endif
    </table>

    @include('pdf._reverse-charge', ['document' => $quote])

    <p class="muted" style="margin-top: 24px;">{{ __('This quote is valid until :date. Accept it in your client area at :url', ['date' => $quote->valid_until->translatedFormat('M j, Y'), 'url' => route('portal.quotes.show', $quote)]) }}</p>
</body>
</html>
