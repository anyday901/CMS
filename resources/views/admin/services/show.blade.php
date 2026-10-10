@php use App\Support\Money; @endphp
<x-admin-layout :title="$service->description()">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $service->description() }}</h1>
        <x-status-badge :status="$service->status" />
        @can('manage-clients')
        <div class="ml-auto flex gap-2">
            @foreach (['suspend' => 'active', 'unsuspend' => 'suspended'] as $action => $from)
                @if ($service->status->value === $from)
                    <form method="POST" action="{{ route('admin.services.action', [$service, $action]) }}">@csrf
                        <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-100">{{ ucfirst($action) }}</button>
                    </form>
                @endif
            @endforeach
            @if (in_array($service->status->value, ['active', 'suspended', 'pending']))
                <form method="POST" action="{{ route('admin.services.action', [$service, 'terminate']) }}" onsubmit="return confirm('Terminate this service? This cannot be undone.')">@csrf
                    <button class="rounded-md px-3 py-1.5 text-sm text-red-700 ring-1 ring-red-300 hover:bg-red-50">Terminate</button>
                </form>
            @endif
        </div>
        @endcan
    </div>

    <div class="mb-8 grid gap-4 rounded-lg border border-gray-200 bg-white p-4 text-sm md:grid-cols-3">
        <div><div class="text-gray-500">Client</div><a href="{{ route('admin.clients.show', $service->client) }}" class="text-indigo-600">{{ $service->client->fullName() }}</a></div>
        <div><div class="text-gray-500">Billing</div>{{ Money::format($service->recurring_amount, $service->client->currency) }} {{ strtolower($service->billing_cycle->label()) }}</div>
        <div><div class="text-gray-500">Next due</div>{{ $service->next_due_date?->format('M j, Y') ?? '—' }}</div>
        <div><div class="text-gray-500">Registered</div>{{ $service->registration_date->format('M j, Y') }}</div>
        @if ($service->suspended_at)<div><div class="text-gray-500">Suspended</div>{{ $service->suspended_at->format('M j, Y') }} ({{ $service->suspension_reason }})</div>@endif
        @if ($service->terminated_at)<div><div class="text-gray-500">Terminated</div>{{ $service->terminated_at->format('M j, Y') }}</div>@endif
    </div>

    @php $module = app(App\Provisioning\ModuleRegistry::class)->find($service->product->module); @endphp
    @if ($module)
        <div class="mb-8 rounded-lg border border-gray-200 bg-white p-4 text-sm">
            <h2 class="mb-2 font-semibold">Provisioning: {{ $module->label() }}</h2>
            @if ($service->provisioning_status)
                <p>Last action: {{ $service->provisioning_action }}, <x-status-badge :status="$service->provisioning_status === 'done' ? 'active' : ($service->provisioning_status === 'failed' ? 'overdue' : 'pending')" /> {{ $service->provisioning_status }}@if ($service->provisioned_at), last success {{ $service->provisioned_at->format('M j, Y g:ia') }}@endif</p>
                @if ($service->provisioning_error)<p class="mt-1 rounded-md bg-red-50 px-3 py-2 text-red-800">{{ $service->provisioning_error }}</p>@endif
            @else
                <p class="text-gray-500">Nothing has run yet. The module creates the service when it becomes active.</p>
            @endif
            @can('manage-clients')
                <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="mt-3 flex flex-wrap items-end gap-2" onsubmit="return confirm('Run this action on {{ e($module->label()) }}?')">
                    @csrf
                    <label class="block">Run
                        <select name="action" class="mt-1 block rounded-md px-2 py-1 ring-1 ring-gray-300">
                            @foreach (App\Provisioning\Provisioner::ACTIONS as $action)
                                <option value="{{ $action }}" @selected($service->provisioning_action === $action)>{{ ucfirst($action) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="rounded-md px-3 py-1 ring-1 ring-gray-300 hover:bg-gray-100">Run again</button>
                </form>
            @endcan
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
    @php $invoices = $service->invoiceItems->pluck('invoice')->unique('id')->sortByDesc('id')->each->setRelation('client', $service->client); @endphp
    @if ($invoices->isEmpty())
        <p class="text-sm text-gray-500">No invoices for this service yet.</p>
    @else
        @include('admin.invoices._table', ['invoices' => $invoices])
    @endif
</x-admin-layout>
