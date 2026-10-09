@php use App\Support\Money; @endphp
<x-portal-layout :title="$service->description()">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $service->description() }}</h1>
        <x-status-badge :status="$service->status" />
    </div>
    <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div><div class="text-gray-500">Price</div>{{ Money::format($service->recurring_amount, auth('client')->user()->currency) }} {{ strtolower($service->billing_cycle->label()) }}</div>
        <div><div class="text-gray-500">Next due</div>{{ $service->next_due_date?->format('M j, Y') ?? '—' }}</div>
        <div><div class="text-gray-500">Started</div>{{ $service->registration_date->format('M j, Y') }}</div>
        @if ($service->product->description)<div class="sm:col-span-3"><div class="text-gray-500">About</div>{!! nl2br(e($service->product->description)) !!}</div>@endif
    </div>
    @if ($service->status->value === 'suspended')
        <p class="mt-4 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">This service is suspended. If you have an unpaid invoice, paying it will restore the service.</p>
    @endif
</x-portal-layout>
