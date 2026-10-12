@php use App\Support\Money; @endphp
<x-admin-layout :title="$service->description()">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $service->description() }}</h1>
        <x-status-badge :status="$service->status" />
        @can('manage-clients')
        <div class="ml-auto flex gap-2">
            @if ($canMove)
                <a href="{{ route('admin.services.move', $service) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Upgrade or downgrade</a>
            @endif
            @foreach (['suspend' => 'active', 'unsuspend' => 'suspended'] as $action => $from)
                @if ($service->status->value === $from)
                    <form method="POST" action="{{ route('admin.services.action', [$service, $action]) }}">@csrf
                        <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ ucfirst($action) }}</button>
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

    <div class="mb-8 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
        <div><div class="text-ink-500">Client</div><a href="{{ route('admin.clients.show', $service->client) }}" class="text-brand-600">{{ $service->client->fullName() }}</a></div>
        <div><div class="text-ink-500">Billing</div>{{ Money::format($service->recurring_amount, $service->client->currency) }} {{ strtolower($service->billing_cycle->label()) }}@if ($service->recurring_discount)<div class="text-ink-500">less {{ Money::format($service->recurring_discount, $service->client->currency) }} each renewal{{ $service->promotion ? " ({$service->promotion->code})" : '' }}</div>@endif</div>
        @if ($service->parent)<div><div class="text-ink-500">Add-on for</div><a href="{{ route('admin.services.show', $service->parent) }}" class="text-brand-600">{{ $service->parent->description() }}</a></div>@endif
        @if ($service->addons->isNotEmpty())<div><div class="text-ink-500">Add-ons</div>@foreach ($service->addons as $addon)<a href="{{ route('admin.services.show', $addon) }}" class="text-brand-600">{{ $addon->product->name }}</a> <span class="text-ink-500">({{ $addon->status->value }})</span>@if (! $loop->last), @endif @endforeach</div>@endif
        @if ($service->order_id)<div><div class="text-ink-500">Order</div><a href="{{ route('admin.orders.show', $service->order_id) }}" class="text-brand-600">#{{ $service->order_id }}</a>@if ($service->order) <x-status-badge :status="$service->order->status" />@endif @if ($service->order?->promotion_code) with {{ $service->order->promotion_code }}@endif</div>@endif
        @foreach ($service->upgrades->where('status', 'pending') as $upgrade)
            <div class="md:col-span-3"><div class="text-ink-500">Pending move</div>To {{ $upgrade->toProduct->name }}@if ($upgrade->to_cycle), {{ strtolower(App\Enums\BillingCycle::from($upgrade->to_cycle)->label()) }},@endif when @if ($upgrade->invoice)<a href="{{ route('admin.invoices.show', $upgrade->invoice) }}" class="text-brand-600">invoice #{{ $upgrade->invoice->number }}</a>@else its invoice @endif is paid</div>
        @endforeach
        @if ($cancellation = $service->pendingCancellation)
            <div class="md:col-span-3 flex flex-wrap items-center gap-3 rounded-md bg-sun-300/40 px-3 py-2 text-amber-900">
                <div><div class="font-medium">Cancellation requested{{ $cancellation->requester ? " by {$cancellation->requester->name}" : ' by the client' }} on {{ $cancellation->created_at->format('M j, Y') }}</div>
                    Cancels on {{ $cancellation->cancel_on->format('M j, Y') }} ({{ strtolower($cancellation->typeLabel()) }}) and won't be renewed.@if ($cancellation->reason) Reason: {{ $cancellation->reason }}@endif</div>
                @can('manage-clients')
                    <form method="POST" action="{{ route('admin.cancellations.destroy', $cancellation) }}" class="ml-auto">@csrf @method('DELETE')
                        <button class="rounded-md px-3 py-1.5 ring-1 ring-amber-400 hover:bg-sun-300/40">Withdraw request</button>
                    </form>
                @endcan
            </div>
        @endif
        <div><div class="text-ink-500">Next due</div>{{ $service->next_due_date?->format('M j, Y') ?? '—' }}@if ($service->trial_ends_on)<div class="text-ink-500">Free trial {{ $service->inTrial() ? 'until' : 'ended' }} {{ $service->trial_ends_on->format('M j, Y') }}</div>@endif</div>
        <div><div class="text-ink-500">Registered</div>{{ $service->registration_date->format('M j, Y') }}</div>
        @if ($service->suspended_at)<div><div class="text-ink-500">Suspended</div>{{ $service->suspended_at->format('M j, Y') }} ({{ $service->suspension_reason }})</div>@endif
        @if ($service->terminated_at)<div><div class="text-ink-500">Terminated</div>{{ $service->terminated_at->format('M j, Y') }}</div>@endif
        @foreach ($service->options->load(['option', 'choice'])->sortBy(fn ($chosen) => [$chosen->option->sort_order, $chosen->option->id]) as $chosen)
            <div><div class="text-ink-500">{{ $chosen->option->name }}</div>{{ match ($chosen->option->type) { 'dropdown' => $chosen->choice->name, 'quantity' => $chosen->quantity, default => 'Yes' } }}</div>
        @endforeach
        @include('custom-fields._values', ['fields' => $fields, 'values' => $service->custom_fields ?? [], 'wide' => 'md:col-span-3', 'showAdminOnly' => true])
    </div>

    @can('manage-clients')
        @if ($service->product->options->isNotEmpty() || $fields->isNotEmpty())
            <details class="mb-8 text-sm" @if ($errors->any()) open @endif>
                <summary class="cursor-pointer text-brand-600">Change options and fields</summary>
                <form method="POST" action="{{ route('admin.services.update', $service) }}" class="mt-3 grid max-w-3xl gap-4 rounded-lg border border-ink-200 bg-white p-4 md:grid-cols-2">
                    @csrf @method('PUT')
                    @include('services._options', [
                        'options' => $service->product->options,
                        'values' => $service->options->mapWithKeys(fn ($chosen) => [$chosen->product_option_id => match ($chosen->option->type) { 'dropdown' => $chosen->product_option_choice_id, 'quantity' => $chosen->quantity, default => '1' }])->all(),
                        'cycleHint' => $service->billing_cycle,
                    ])
                    @include('custom-fields._inputs', ['fields' => $fields, 'values' => $service->custom_fields ?? [], 'wide' => 'md:col-span-2'])
                    @if ($service->product->options->isNotEmpty())
                        <p class="text-ink-500 md:col-span-2">A change in option prices is added to or taken off the recurring amount from the next invoice.</p>
                    @endif
                    <div class="md:col-span-2"><button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button></div>
                </form>
            </details>
        @endif
    @endcan

    @can('manage-clients')
        @if ($canCancel)
            <details class="mb-8 text-sm" @if ($errors->has('cancellation')) open @endif>
                <summary class="cursor-pointer text-brand-600">Request cancellation for the client</summary>
                @include('services._cancel-form', ['action' => route('admin.cancellations.store', $service), 'periodEnd' => $service->next_due_date])
            </details>
        @endif
    @endcan

    @php $module = app(App\Provisioning\ModuleRegistry::class)->find($service->product->module); @endphp
    @if ($module)
        <div class="mb-8 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="mb-2 font-semibold">Provisioning: {{ $module->label() }}</h2>
            @if ($service->provisioning_status)
                <p>Last action: {{ $service->provisioning_action }}, <x-status-badge :status="$service->provisioning_status === 'done' ? 'active' : ($service->provisioning_status === 'failed' ? 'overdue' : 'pending')" /> {{ $service->provisioning_status }}@if ($service->provisioned_at), last success {{ $service->provisioned_at->format('M j, Y g:ia') }}@endif</p>
                @if ($service->provisioning_error)<p class="mt-1 rounded-md bg-red-50 px-3 py-2 text-red-800">{{ $service->provisioning_error }}</p>@endif
            @else
                <p class="text-ink-500">Nothing has run yet. The module creates the service when it becomes active.</p>
            @endif
            @can('manage-clients')
                <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="mt-3 flex flex-wrap items-end gap-2" onsubmit="return confirm('Run this action on {{ e($module->label()) }}?')">
                    @csrf
                    <label class="block">Run
                        <select name="action" class="mt-1 block rounded-md px-2 py-1 ring-1 ring-ink-300">
                            @foreach (array_filter(App\Provisioning\Provisioner::ACTIONS, fn ($action) => App\Provisioning\Provisioner::supports($module, $action)) as $action)
                                <option value="{{ $action }}" @selected($service->provisioning_action === $action)>{{ App\Provisioning\Provisioner::label($action) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Run again</button>
                </form>
            @endcan
        </div>
    @endif

    @php $tasks = App\Models\FulfillmentTask::where('service_id', $service->id)->with(['service.client', 'service.product', 'completedBy'])->latest('id')->get(); @endphp
    @if ($tasks->isNotEmpty())
        <h2 class="mb-3 text-lg font-semibold">Tasks</h2>
        <div class="mb-8">@include('admin.tasks._table')</div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
    @php $invoices = $service->invoiceItems->pluck('invoice')->unique('id')->sortByDesc('id')->each->setRelation('client', $service->client); @endphp
    @if ($invoices->isEmpty())
        <p class="text-sm text-ink-500">No invoices for this service yet.</p>
    @else
        @include('admin.invoices._table', ['invoices' => $invoices])
    @endif
</x-admin-layout>
