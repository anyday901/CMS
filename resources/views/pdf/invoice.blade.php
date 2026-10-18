@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $invoice->label() }}</title>
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
                <h1>{{ $invoice->label() }}</h1>
                @php $status = $invoice->status->value; @endphp
                <div style="margin: 6px 0;"><span class="status {{ in_array($status, ['paid', 'unpaid']) ? 'status-'.$status : 'status-other' }}">{{ __(ucfirst($status)) }}</span></div>
                <div>{{ __('Date: :date', ['date' => $invoice->issue_date->translatedFormat('M j, Y')]) }}</div>
                <div>{{ __('Due: :date', ['date' => $invoice->due_date->translatedFormat('M j, Y')]) }}</div>
                @if ($invoice->paid_at)<div>{{ __('Paid: :date', ['date' => $invoice->paid_at->translatedFormat('M j, Y')]) }}</div>@endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 24px;">
        <div class="muted">{{ __('Bill to') }}</div>
        @php $client = $invoice->client; @endphp
        <div><strong>{{ $client->company ?: $client->fullName() }}</strong></div>
        @if ($client->company)<div>{{ $client->fullName() }}</div>@endif
        @foreach ([$client->address1, $client->address2, collect([$client->city, $client->state, $client->postcode])->filter()->join(', '), $client->country] as $line)
            @if ($line)<div>{{ $line }}</div>@endif
        @endforeach
        <div>{{ $client->email }}</div>
        @if ($invoice->client_tax_id)<div>{{ __('Tax ID:') }} {{ $invoice->client_tax_id }}</div>@endif
    </div>

    <table class="items">
        <thead><tr><th>{{ __('Description') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr><td>{{ $item->description }}</td><td class="num">{{ Money::format($item->amount, $invoice->currency) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" role="presentation" style="margin-top: 8px;">
        @if ($invoice->tax && ! $invoice->tax_inclusive)
            <tr><td class="label">{{ __('Subtotal') }}</td><td class="num" style="width: 120px;">{{ Money::format($invoice->subtotal, $invoice->currency) }}</td></tr>
            @foreach ($invoice->taxLines() as [$taxLabel, $taxAmount])<tr><td class="label">{{ $taxLabel }}</td><td class="num">{{ Money::format($taxAmount, $invoice->currency) }}</td></tr>@endforeach
        @endif
        <tr class="grand"><td class="label">{{ __('Total') }}</td><td class="num" style="width: 120px;">{{ Money::format($invoice->total, $invoice->currency) }}</td></tr>
        @if ($invoice->tax && $invoice->tax_inclusive)
            @foreach ($invoice->taxLines() as [$taxLabel, $taxAmount])<tr><td class="label">{{ __('Includes :tax', ['tax' => $taxLabel]) }}</td><td class="num">{{ Money::format($taxAmount, $invoice->currency) }}</td></tr>@endforeach
        @endif
        @if ($invoice->amountPaid())
            <tr><td class="label">{{ __('Paid') }}</td><td class="num">{{ Money::format($invoice->amountPaid(), $invoice->currency) }}</td></tr>
        @endif
        <tr class="grand"><td class="label">{{ __('Balance due') }}</td><td class="num">{{ Money::format(max(0, $invoice->balance()), $invoice->currency) }}</td></tr>
    </table>

    @include('pdf._reverse-charge', ['document' => $invoice])

    @if ($invoice->transactions->isNotEmpty())
        <div style="margin-top: 24px;">
            <div class="muted">{{ __('Payments') }}</div>
            @foreach ($invoice->transactions as $transaction)
                <div>{{ $transaction->created_at->translatedFormat('M j, Y') }} · {{ $transaction->isRefund() ? __('Refund to :method', ['method' => $transaction->methodLabel()]) : $transaction->methodLabel() }} · {{ Money::format($transaction->amount, $transaction->currency) }}</div>
            @endforeach
        </div>
    @endif

    @if ($invoice->status === App\Enums\InvoiceStatus::Unpaid)
        @foreach ($offlineGateways ?? [] as $gateway)
            <div style="margin-top: 24px;">
                <div class="muted">{{ $gateway->label() }}</div>
                <div>{!! nl2br(e($gateway->instructions())) !!}</div>
                <div>{{ __('Use invoice #:number as the reference.', ['number' => $invoice->number]) }}</div>
            </div>
        @endforeach
    @endif

    @if ($invoice->notes)
        <div style="margin-top: 24px;">
            <div class="muted">{{ __('Notes') }}</div>
            <div>{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif
</body>
</html>
