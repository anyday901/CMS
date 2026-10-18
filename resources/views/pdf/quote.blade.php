@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Quote {{ $quote->number }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    h1 { font-size: 22px; margin: 0; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .header td { vertical-align: top; }
    .items { margin-top: 24px; }
    .items th { text-align: left; border-bottom: 1px solid #d1d5db; padding: 6px 4px; color: #6b7280; }
    .items td { border-bottom: 1px solid #f3f4f6; padding: 6px 4px; }
    .num { text-align: right; white-space: nowrap; }
    .totals td { padding: 4px; }
    .totals .label { text-align: right; }
    .grand td { font-weight: bold; border-top: 1px solid #d1d5db; }
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
                @if ($company['tax_id'])<div>Tax ID: {{ $company['tax_id'] }}</div>@endif
            </td>
            <td style="text-align: right;">
                <h1>Quote #{{ $quote->number }}</h1>
                <div style="margin: 6px 0;">{{ $quote->subject }}</div>
                <div>Date: {{ $quote->issue_date->format('M j, Y') }}</div>
                <div>Valid until: {{ $quote->valid_until->format('M j, Y') }}</div>
                @if ($quote->accepted_at)<div>Accepted: {{ $quote->accepted_at->format('M j, Y') }}</div>@endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 24px;">
        <div class="muted">Prepared for</div>
        @php $client = $quote->client; @endphp
        <div><strong>{{ $client->company ?: $client->fullName() }}</strong></div>
        @if ($client->company)<div>{{ $client->fullName() }}</div>@endif
        @foreach ([$client->address1, $client->address2, collect([$client->city, $client->state, $client->postcode])->filter()->join(', '), $client->country] as $line)
            @if ($line)<div>{{ $line }}</div>@endif
        @endforeach
        <div>{{ $client->email }}</div>
    </div>

    @if ($quote->notes)
        <div style="margin-top: 24px;">{!! nl2br(e($quote->notes)) !!}</div>
    @endif

    <table class="items">
        <thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr><td>{{ $item->description }}</td><td class="num">{{ Money::format($item->amount, $quote->currency) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" role="presentation" style="margin-top: 8px;">
        @if ($quote->tax && ! $quote->tax_inclusive)
            <tr><td class="label">Subtotal</td><td class="num" style="width: 120px;">{{ Money::format($quote->subtotal, $quote->currency) }}</td></tr>
            <tr><td class="label">{{ $quote->taxLabel() }}</td><td class="num">{{ Money::format($quote->tax, $quote->currency) }}</td></tr>
        @endif
        <tr class="grand"><td class="label">Total</td><td class="num" style="width: 120px;">{{ Money::format($quote->total, $quote->currency) }}</td></tr>
        @if ($quote->tax && $quote->tax_inclusive)
            <tr><td class="label">Includes {{ $quote->taxLabel() }}</td><td class="num">{{ Money::format($quote->tax, $quote->currency) }}</td></tr>
        @endif
    </table>

    <p class="muted" style="margin-top: 24px;">This quote is valid until {{ $quote->valid_until->format('M j, Y') }}. Accept it in your client area at {{ route('portal.quotes.show', $quote) }}</p>
</body>
</html>
