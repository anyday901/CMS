@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">Order</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Items</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2">Invoice</th><th class="px-4 py-2">Status</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($orders as $order)
                <tr>
                    <td class="whitespace-nowrap px-4 py-2"><a href="{{ route('admin.orders.show', $order) }}" class="text-brand-600">#{{ $order->id }}</a>
                        @if ($order->whmcs_number)<span class="text-ink-500">(WHMCS {{ $order->whmcs_number }})</span>@endif
                        <span class="text-ink-500">{{ $order->created_at->format('M j, Y g:ia') }}</span></td>
                    <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $order->client) }}" class="text-brand-600">{{ $order->client->fullName() }}</a></td>
                    <td class="px-4 py-2">{{ $order->services->map(fn ($service) => $service->description())->join(', ') }}</td>
                    <td class="whitespace-nowrap px-4 py-2 text-right">{{ $order->invoice ? Money::format($order->invoice->total, $order->invoice->currency) : '' }}</td>
                    <td class="px-4 py-2">@if ($order->invoice)<x-status-badge :status="$order->invoice->status" />@endif</td>
                    <td class="px-4 py-2"><x-status-badge :status="$order->status" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
