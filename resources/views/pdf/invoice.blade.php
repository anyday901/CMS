@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice->number }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    h1 { font-size: 22px; margin: 0; }
    .muted { color: #6b7280; }
    .status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: bold; text-transform: uppercase; font-size: 10px; }
    .status-paid { background: #dcfce7; color: #166534; }
    .status-unpaid { background: #fef9c3; color: #854d0e; }
    .status-other { background: #f3f4f6; color: #374151; }
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
                <h1>{{ $company['name'] }}</h1>
                @if ($company['address'])<div>{!! nl2br(e(str_replace('\n', "\n", $company['address']))) !!}</div>@endif
                @if ($company['email'])<div>{{ $company['email'] }}</div>@endif
                @if ($company['tax_id'])<div>Tax ID: {{ $company['tax_id'] }}</div>@endif
            </td>
            <td style="text-align: right;">
                <h1>Invoice #{{ $invoice->number }}</h1>
                @php $status = $invoice->status->value; @endphp
                <div style="margin: 6px 0;"><span class="status {{ in_array($status, ['paid', 'unpaid']) ? 'status-'.$status : 'status-other' }}">{{ $status }}</span></div>
                <div>Date: {{ $invoice->issue_date->format('M j, Y') }}</div>
                <div>Due: {{ $invoice->due_date->format('M j, Y') }}</div>
                @if ($invoice->paid_at)<div>Paid: {{ $invoice->paid_at->format('M j, Y') }}</div>@endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 24px;">
        <div class="muted">Bill to</div>
        @php $client = $invoice->client; @endphp
        <div><strong>{{ $client->company ?: $client->fullName() }}</strong></div>
        @if ($client->company)<div>{{ $client->fullName() }}</div>@endif
        @foreach ([$client->address1, $client->address2, collect([$client->city, $client->state, $client->postcode])->filter()->join(', '), $client->country] as $line)
            @if ($line)<div>{{ $line }}</div>@endif
        @endforeach
        <div>{{ $client->email }}</div>
    </div>

    <table class="items">
        <thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr><td>{{ $item->description }}</td><td class="num">{{ Money::format($item->amount, $invoice->currency) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" role="presentation" style="margin-top: 8px;">
        @if ($invoice->tax && ! $invoice->tax_inclusive)
            <tr><td class="label">Subtotal</td><td class="num" style="width: 120px;">{{ Money::format($invoice->subtotal, $invoice->currency) }}</td></tr>
            <tr><td class="label">{{ $invoice->taxLabel() }}</td><td class="num">{{ Money::format($invoice->tax, $invoice->currency) }}</td></tr>
        @endif
        <tr class="grand"><td class="label">Total</td><td class="num" style="width: 120px;">{{ Money::format($invoice->total, $invoice->currency) }}</td></tr>
        @if ($invoice->tax && $invoice->tax_inclusive)
            <tr><td class="label">Includes {{ $invoice->taxLabel() }}</td><td class="num">{{ Money::format($invoice->tax, $invoice->currency) }}</td></tr>
        @endif
        @if ($invoice->amountPaid())
            <tr><td class="label">Paid</td><td class="num">{{ Money::format($invoice->amountPaid(), $invoice->currency) }}</td></tr>
        @endif
        <tr class="grand"><td class="label">Balance due</td><td class="num">{{ Money::format(max(0, $invoice->balance()), $invoice->currency) }}</td></tr>
    </table>

    @if ($invoice->transactions->isNotEmpty())
        <div style="margin-top: 24px;">
            <div class="muted">Payments</div>
            @foreach ($invoice->transactions as $transaction)
                <div>{{ $transaction->created_at->format('M j, Y') }} · {{ $transaction->isRefund() ? 'Refund to ' : '' }}{{ $transaction->methodLabel() }} · {{ Money::format($transaction->amount, $transaction->currency) }}</div>
            @endforeach
        </div>
    @endif

    @if ($invoice->notes)
        <div style="margin-top: 24px;">
            <div class="muted">Notes</div>
            <div>{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif
</body>
</html>
