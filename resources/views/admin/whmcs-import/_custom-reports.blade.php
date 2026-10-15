@php
    // WHMCS's own reports and the closest one under Reports here.
    $matches = [
        'Annual Income Report' => 'annual-income',
        'Monthly Transactions' => 'monthly-transactions',
        'Daily Performance' => 'daily-performance',
        'Income Forecast' => 'income-forecast',
        'Income by Product' => 'income-by-product',
        'New Customers, Sales Product Mix' => 'new-orders-and-clients',
        'Promotions Usage' => 'promotion-usage',
        'Top 10 Clients by Income' => 'top-clients',
        'Aging Invoices' => 'invoice-aging',
        'Credits Reviewer' => 'credits',
        'Client Statement' => 'client-statement',
        'Sales Tax Liability, VAT Moss, EU VAT Invoices' => 'tax-collected',
        'Clients by Country' => 'clients-by-country',
        'Customer Retention Time' => 'retention',
        'Cancellation Requests' => 'suspensions-and-cancellations',
        'Server Revenue Forecasts' => 'server-revenue',
        'Support Ticket Replies' => 'replies-by-staff',
        'Ticket Ratings Reviewer' => 'ticket-feedback',
        'Ticket Tags' => 'ticket-tags',
    ];
    $reports = app(App\Reports\ReportRegistry::class)->all();
@endphp
<details class="mb-6 rounded-md bg-ink-50 px-4 py-3 text-ink-600" @if ($open ?? false) open @endif>
    <summary class="cursor-pointer font-medium text-ink-800">Custom WHMCS reports can't be copied</summary>
    <p class="mt-2">WHMCS reports are PHP files in the <code class="rounded bg-white px-1">modules/reports</code> folder on the WHMCS server. The import only reads the WHMCS database, so it never sees them, and a custom report someone wrote for you won't come over. The history reports read does come over (invoices, payments, services, orders, promotions, the credit log, cancellation requests, tickets and the activity log), so the built-in reports here cover the years before the switch.</p>
    <p class="mt-2">For a custom report, note what it showed and export its numbers from WHMCS before you turn it off. The closest built-in reports here:</p>
    <ul class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
        @foreach ($matches as $whmcs => $key)
            <li>{{ $whmcs }}: @can('view-reports')<a href="{{ route('admin.reports.show', $key) }}" class="text-brand-600">{{ $reports[$key]->title() }}</a>@else{{ $reports[$key]->title() }}@endcan</li>
        @endforeach
    </ul>
</details>
