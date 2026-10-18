@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Credit note {{ $note->number }}</title>
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
                <h1>Credit note {{ $note->number }}</h1>
                <div style="margin-top: 6px;">Date: {{ $note->created_at->format('M j, Y') }}</div>
                <div>Against invoice #{{ $note->invoice->number }} of {{ $note->invoice->issue_date->format('M j, Y') }}</div>
            </td>
        </tr>
    </table>

    <div style="margin-top: 24px;">
        <div class="muted">Credit to</div>
        @php $client = $note->client; @endphp
        <div><strong>{{ $client->company ?: $client->fullName() }}</strong></div>
        @if ($client->company)<div>{{ $client->fullName() }}</div>@endif
        @foreach ([$client->address1, $client->address2, collect([$client->city, $client->state, $client->postcode])->filter()->join(', '), $client->country] as $line)
            @if ($line)<div>{{ $line }}</div>@endif
        @endforeach
        <div>{{ $client->email }}</div>
        @if ($note->client_tax_id)<div>Tax ID: {{ $note->client_tax_id }}</div>@endif
    </div>

    <table class="items">
        <thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
        <tbody>
            @foreach ($note->items as $item)
                <tr><td>{{ $item->description }}</td><td class="num">{{ Money::format($item->amount, $note->currency) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" role="presentation" style="margin-top: 8px;">
        @if ($note->tax && ! $note->tax_inclusive)
            <tr><td class="label">Subtotal</td><td class="num" style="width: 120px;">{{ Money::format($note->subtotal, $note->currency) }}</td></tr>
            @foreach ($note->taxLines() as [$taxLabel, $taxAmount])<tr><td class="label">{{ $taxLabel }}</td><td class="num">{{ Money::format($taxAmount, $note->currency) }}</td></tr>@endforeach
        @endif
        <tr class="grand"><td class="label">Total credited</td><td class="num" style="width: 120px;">{{ Money::format($note->total, $note->currency) }}</td></tr>
        @if ($note->tax && $note->tax_inclusive)
            @foreach ($note->taxLines() as [$taxLabel, $taxAmount])<tr><td class="label">Includes {{ $taxLabel }}</td><td class="num">{{ Money::format($taxAmount, $note->currency) }}</td></tr>@endforeach
        @endif
    </table>

    @include('pdf._reverse-charge', ['document' => $note])

    <div style="margin-top: 24px;">
        <div class="muted">Reason</div>
        <div>{!! nl2br(e($note->reason)) !!}</div>
    </div>

    <div style="margin-top: 24px;">
        <div class="muted">Paid back</div>
        @if ($note->refund_mode === 'credit')
            <div>Added to your account credit.</div>
        @else
            @foreach ($note->refunds as $refund)
                <div>Refunded to your {{ $refund->methodLabel() }} payment{{ $refund->refundOf ? ' of '.$refund->refundOf->created_at->format('M j, Y') : '' }}.</div>
            @endforeach
        @endif
    </div>
</body>
</html>
