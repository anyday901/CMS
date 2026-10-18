@php use App\Support\Money; @endphp
<x-admin-layout :title="'Order #'.$order->id">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Order #{{ $order->id }}</h1>
        <x-status-badge :status="$order->status" />
        @if ($order->whmcs_number)<span class="text-sm text-ink-500">WHMCS order number {{ $order->whmcs_number }}</span>@endif
    </div>

    <div class="mb-6 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-4">
        <div><div class="text-ink-500">Client</div><a href="{{ route('admin.clients.show', $order->client) }}" class="text-brand-600">{{ $order->client->fullName() }}</a>
            <div class="text-ink-500">{{ $order->client->email }}</div></div>
        <div><div class="text-ink-500">Placed</div>{{ $order->created_at->format('M j, Y g:ia') }}
            @if ($order->ip_address)<div class="text-ink-500">from {{ $order->ip_address }}</div>@endif
            @if ($order->terms_accepted_at)<div class="text-ink-500">Agreed to the terms of service</div>@endif</div>
        <div><div class="text-ink-500">Invoice</div>
            @if ($order->invoice)
                <a href="{{ route('admin.invoices.show', $order->invoice) }}" class="text-brand-600">#{{ $order->invoice->number }}</a>
                {{ Money::format($order->invoice->total, $order->invoice->currency) }} <x-status-badge :status="$order->invoice->status" />
            @else
                <span class="text-ink-500">None</span>
            @endif
        </div>
        <div><div class="text-ink-500">Promotion code</div>{{ $order->promotion_code ?? 'None' }}</div>
    </div>

    <h2 class="mb-3 text-lg font-semibold">Services</h2>
    <div class="mb-6 overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500">
                <tr><th class="px-4 py-2">Service</th><th class="px-4 py-2">Billing</th><th class="px-4 py-2">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($order->services as $service)
                    <tr>
                        <td class="px-4 py-2 {{ $service->parent_service_id ? 'pl-8' : '' }}"><a href="{{ route('admin.services.show', $service) }}" class="text-brand-600">{{ $service->parent_service_id ? '+ ' : '' }}{{ $service->description() }}</a>
                            @if ($service->trial_ends_on)<div class="text-ink-500">Free trial, first payment due {{ $service->trial_ends_on->format('M j, Y') }}</div>@endif</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ Money::format($service->recurring_amount, $order->client->currency) }} {{ strtolower($service->billing_cycle->label()) }}</td>
                        <td class="px-4 py-2"><x-status-badge :status="$service->status" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($order->reviewed_at)
        <p class="mb-6 text-sm text-ink-600">{{ $order->status->label() }} {{ $order->reviewed_at->format('M j, Y g:ia') }}@if ($order->reviewer) by {{ $order->reviewer->name }}@endif.</p>
    @endif

    @can('manage-billing')
        <div class="flex flex-wrap gap-2 text-sm">
            @if ($order->isPending())
                <form method="POST" action="{{ route('admin.orders.accept', $order) }}">@csrf
                    <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Accept</button>
                </form>
            @endif
            @if (in_array($order->status->value, ['pending', 'active'], true))
                <form method="POST" action="{{ route('admin.orders.cancel', $order) }}" onsubmit="return confirm('Cancel this order? Its unpaid invoice is cancelled and running services are terminated.')">@csrf
                    <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Cancel order</button>
                </form>
                <form method="POST" action="{{ route('admin.orders.fraud', $order) }}" onsubmit="return confirm('Mark this order as fraud? Its unpaid invoice is cancelled and running services are terminated.')">@csrf
                    <button class="rounded-full px-4 py-2 text-red-700 ring-1 ring-red-300 hover:bg-red-50">Mark as fraud</button>
                </form>
            @endif
        </div>
        @if ($order->isPending())
            <p class="mt-3 text-sm text-ink-500">
                @if (config('billing.hold_new_orders'))
                    New orders are held for review: services on a paid order start when you accept it.
                @else
                    Services start as soon as the invoice is paid. Accepting marks the order as reviewed. To hold paid orders until they're accepted, turn that on under <a href="{{ route('admin.settings.edit') }}" class="text-brand-600">Settings</a>.
                @endif
            </p>
        @endif
    @endcan
</x-admin-layout>
