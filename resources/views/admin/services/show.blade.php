@php
    use App\Support\Money;
    $staff = auth()->user()->can('manage-clients');
    $section = 'services';
    $row = 'grid grid-cols-1 gap-1 px-4 py-2 sm:grid-cols-[10rem_1fr] sm:items-center sm:gap-3';
    $label = 'text-ink-500 sm:text-right';
    $input = 'block w-full max-w-sm rounded-md px-3 py-1.5 ring-1 ring-ink-300';
    $error = fn (string $field) => $errors->has($field) ? '<span class="block text-red-700">'.e($errors->first($field)).'</span>' : '';
    $domainLabel = $module instanceof App\Provisioning\UsesServers && ! $module->needsDomain() ? 'Hostname' : 'Domain';
    $saved = collect($service->provisioning_data ?? [])->filter(fn ($value) => is_scalar($value) && $value !== '');
@endphp
<x-admin-layout :title="$service->description().' · '.$client->fullName()">
    @include('admin.clients._header')

    <div class="flex flex-col gap-6 md:flex-row md:items-start">
        @include('admin.clients._nav')

        <div class="min-w-0 grow">
            {{-- Switch between the client's services without leaving their page, as WHMCS does. --}}
            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-ink-200 bg-ink-50 p-3 text-sm">
                <form method="GET" action="{{ route('admin.clients.show', $client) }}" class="flex min-w-0 grow flex-wrap gap-2">
                    <label for="service-picker" class="sr-only">Service</label>
                    <select id="service-picker" name="service" onchange="this.form.submit()" class="min-w-0 grow rounded-md bg-white px-3 py-1.5 ring-1 ring-ink-300 sm:max-w-xl">
                        @foreach ($clientServices as $other)
                            <option value="{{ $other->id }}" @selected($other->id === $service->id)>{{ $other->description() }} ({{ __(ucfirst($other->status->value)) }})</option>
                        @endforeach
                    </select>
                    <button class="rounded-md bg-white px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Go</button>
                </form>
                @if ($canMove && $staff)
                    <a href="{{ route('admin.services.move', $service) }}" class="rounded-md bg-white px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Upgrade or downgrade</a>
                @endif
            </div>

            @foreach ($service->upgrades->where('status', 'pending') as $upgrade)
                <p class="mb-4 rounded-md bg-sun-300/40 px-3 py-2 text-sm text-amber-900">Pending move to {{ $upgrade->toProduct->name }}@if ($upgrade->to_cycle), {{ strtolower(App\Enums\BillingCycle::from($upgrade->to_cycle)->label()) }},@endif when @if ($upgrade->invoice)<a href="{{ route('admin.invoices.show', $upgrade->invoice) }}" class="underline">invoice #{{ $upgrade->invoice->number }}</a>@else its invoice @endif is paid.</p>
            @endforeach
            @if ($cancellation = $service->pendingCancellation)
                <div class="mb-4 flex flex-wrap items-center gap-3 rounded-md bg-sun-300/40 px-3 py-2 text-sm text-amber-900">
                    <div><div class="font-medium">Cancellation requested{{ $cancellation->requester ? " by {$cancellation->requester->name}" : ' by the client' }} on {{ $cancellation->created_at->format('M j, Y') }}</div>
                        Cancels on {{ $cancellation->cancel_on->format('M j, Y') }} ({{ strtolower($cancellation->typeLabel()) }}) and won't be renewed.@if ($cancellation->reason) Reason: {{ $cancellation->reason }}@endif</div>
                    @can('manage-clients')
                        <form method="POST" action="{{ route('admin.cancellations.destroy', $cancellation) }}" class="ml-auto">@csrf @method('DELETE')
                            <button class="rounded-md px-3 py-1.5 ring-1 ring-amber-400 hover:bg-sun-300/40">Withdraw request</button>
                        </form>
                    @endcan
                </div>
            @endif
            @error('account')<p class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

            {{-- The service's details in two columns, as on WHMCS's service tab. Staff who manage clients edit them in place. --}}
            @if ($staff)
                <form id="service-details" method="POST" action="{{ route('admin.services.details', $service) }}">@csrf @method('PUT')</form>
            @endif
            <div class="mb-6 overflow-hidden rounded-lg border border-ink-200 bg-white text-sm">
                <div class="grid md:grid-cols-2 md:divide-x md:divide-ink-100">
                    <dl class="divide-y divide-ink-100">
                        <div class="{{ $row }}"><dt class="{{ $label }}">Order</dt>
                            <dd>@if ($service->order_id)<a href="{{ route('admin.orders.show', $service->order_id) }}" class="text-brand-600">#{{ $service->order_id }} · View order</a>@if ($service->order) <x-status-badge :status="$service->order->status" />@endif @if ($service->order?->promotion_code) with {{ $service->order->promotion_code }}@endif @else <span class="text-ink-500">Added by staff</span>@endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}">Product/Service</dt>
                            <dd>{{ $service->product->name }}@if ($canMove && $staff) · <a href="{{ route('admin.services.move', $service) }}" class="text-brand-600">change</a>@endif</dd></div>
                        @if ($service->parent)
                            <div class="{{ $row }}"><dt class="{{ $label }}">Add-on for</dt><dd><a href="{{ route('admin.services.show', $service->parent) }}" class="text-brand-600">{{ $service->parent->description() }}</a></dd></div>
                        @endif
                        @if ($service->addons->isNotEmpty())
                            <div class="{{ $row }}"><dt class="{{ $label }}">Add-ons</dt><dd>@foreach ($service->addons as $addon)<a href="{{ route('admin.services.show', $addon) }}" class="text-brand-600">{{ $addon->product->name }}</a> <span class="text-ink-500">({{ $addon->status->value }})</span>@if (! $loop->last), @endif @endforeach</dd></div>
                        @endif
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="server_id">Server</label></dt>
                            <dd>@if ($staff)
                                    <select id="server_id" name="server_id" form="service-details" class="{{ $input }}">
                                        <option value="">None</option>
                                        @foreach ($serverChoices as $choice)
                                            <option value="{{ $choice->id }}" @selected((string) old('server_id', $service->server_id) === (string) $choice->id)>{{ $choice->name }} ({{ $choice->hostname }})</option>
                                        @endforeach
                                    </select>
                                    {!! $error('server_id') !!}
                                    @if ($servers !== null)<span class="text-ink-500">Doesn't move the account; use it when it was moved another way.</span>@endif
                                @elseif ($service->server)
                                    {{ $service->server->name }} <span class="text-ink-500">{{ $service->server->hostname }}</span>
                                @else
                                    None
                                @endif
                                @if ($service->server)
                                    @can('manage-settings')<a href="{{ route('admin.servers.edit', $service->server) }}" class="text-brand-600">Edit {{ $service->server->name }}</a>@endcan
                                @elseif ($servers !== null)
                                    <span class="block text-ink-500">Left as none, one is chosen when the service is created.</span>
                                @endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="label">{{ $domainLabel }}</label></dt>
                            <dd>@if ($staff)
                                    <input id="label" name="label" form="service-details" value="{{ old('label', $service->label) }}" placeholder="{{ $domainLabel === 'Domain' ? 'example.com' : '' }}" class="{{ $input }}">{!! $error('label') !!}
                                @else
                                    {{ $service->label ?: '—' }}
                                @endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="username">Username</label></dt>
                            <dd>@if ($staff)
                                    <input id="username" name="username" form="service-details" value="{{ old('username', $service->username) }}" @if ($servers !== null) placeholder="Made from the domain when blank" @endif class="{{ $input }}">{!! $error('username') !!}
                                @else
                                    {{ $service->username ?: '—' }}
                                @endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="password">Password</label></dt>
                            <dd>@if ($staff)
                                    @if (filled($service->password))
                                        <details class="mb-1"><summary class="cursor-pointer text-brand-600">Show current</summary><code class="break-all">{{ $service->password }}</code></details>
                                    @endif
                                    <input id="password" type="password" name="password" form="service-details" autocomplete="new-password" placeholder="{{ filled($service->password) ? 'Leave blank to keep it' : 'None set' }}" class="{{ $input }}">{!! $error('password') !!}
                                    @if ($servers !== null && App\Provisioning\Provisioner::supports($module, 'change_password'))<span class="text-ink-500">Also set on the server once the account exists.</span>@endif
                                @else
                                    {{ blank($service->password) ? '—' : 'Set' }}
                                @endif
                                @if (filled($service->new_password))
                                    <span class="block text-ink-500">A new password is waiting to be set on the server; the client keeps this one until it is.</span>
                                @endif</dd></div>
                        @if ($saved->isNotEmpty())
                            <div class="{{ $row }}"><dt class="{{ $label }}">Saved by the module</dt><dd>{{ $saved->map(fn ($value, $key) => str_replace('_', ' ', $key).': '.$value)->join(', ') }}</dd></div>
                        @endif
                        <div class="{{ $row }}"><dt class="{{ $label }}">Status</dt>
                            <dd><x-status-badge :status="$service->status" />
                                @if ($service->suspended_at)<span class="text-ink-500">since {{ $service->suspended_at->format('M j, Y') }} ({{ $service->suspension_reason }})</span>@endif</dd></div>
                    </dl>

                    <dl class="divide-y divide-ink-100 border-t border-ink-100 md:border-t-0">
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="registration_date">Registration date</label></dt>
                            <dd>@if ($staff)
                                    <input id="registration_date" type="date" name="registration_date" form="service-details" value="{{ old('registration_date', $service->registration_date->toDateString()) }}" class="{{ $input }}">{!! $error('registration_date') !!}
                                @else
                                    {{ $service->registration_date->format('M j, Y') }}
                                @endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="recurring_amount">Recurring amount</label></dt>
                            <dd>@if ($staff)
                                    <div class="flex max-w-sm items-center gap-2"><input id="recurring_amount" name="recurring_amount" form="service-details" inputmode="decimal" value="{{ old('recurring_amount', Money::toInput($service->recurring_amount)) }}" class="{{ $input }}"><span class="text-ink-500">{{ $client->currency }}</span></div>{!! $error('recurring_amount') !!}
                                @else
                                    {{ Money::format($service->recurring_amount, $client->currency) }}
                                @endif
                                @if ($service->recurring_discount)<span class="block text-ink-500">Less {{ Money::format($service->recurring_discount, $client->currency) }} each renewal{{ $service->promotion ? " ({$service->promotion->code})" : '' }}</span>@endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="next_due_date">Next due date</label></dt>
                            <dd>@if ($staff)
                                    <input id="next_due_date" type="date" name="next_due_date" form="service-details" value="{{ old('next_due_date', $service->next_due_date?->toDateString()) }}" class="{{ $input }}">{!! $error('next_due_date') !!}
                                @else
                                    {{ $service->next_due_date?->format('M j, Y') ?? '—' }}
                                @endif
                                @if ($service->trial_ends_on)<span class="block text-ink-500">Free trial {{ $service->inTrial() ? 'until' : 'ended' }} {{ $service->trial_ends_on->format('M j, Y') }}</span>@endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}">Termination date</dt>
                            <dd>{{ $service->terminated_at?->format('M j, Y') ?? '—' }}</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}"><label for="billing_cycle">Billing cycle</label></dt>
                            <dd>@if ($staff)
                                    <select id="billing_cycle" name="billing_cycle" form="service-details" class="{{ $input }}">
                                        @foreach (App\Enums\BillingCycle::cases() as $cycle)
                                            <option value="{{ $cycle->value }}" @selected(old('billing_cycle', $service->billing_cycle->value) === $cycle->value)>{{ $cycle->label() }}</option>
                                        @endforeach
                                    </select>{!! $error('billing_cycle') !!}
                                @else
                                    {{ $service->billing_cycle->label() }}
                                @endif</dd></div>
                        <div class="{{ $row }}"><dt class="{{ $label }}">Promotion code</dt>
                            <dd>{{ $service->promotion?->code ?? 'None' }}</dd></div>
                        @if ($staff)
                            <div class="px-4 py-3 sm:pl-[calc(10rem+1.75rem)]">
                                <button form="service-details" class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save changes</button>
                                <p class="mt-2 text-ink-500">Billing changes apply from the next invoice; nothing is re-invoiced.</p>
                            </div>
                        @endif
                    </dl>
                </div>

                @can('manage-clients')
                    <div class="flex flex-col gap-2 border-t border-ink-100 bg-ink-50 px-4 py-3 sm:flex-row sm:items-center sm:gap-3">
                        <div class="text-ink-500 sm:w-[10rem] sm:shrink-0 sm:text-right">Module commands</div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach (['suspend' => 'active', 'unsuspend' => 'suspended'] as $action => $from)
                                @if ($service->status->value === $from)
                                    <form method="POST" action="{{ route('admin.services.action', [$service, $action]) }}">@csrf
                                        <button class="rounded-md bg-white px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ ucfirst($action) }}</button>
                                    </form>
                                @endif
                            @endforeach
                            @if (in_array($service->status->value, ['active', 'suspended', 'pending']))
                                <form method="POST" action="{{ route('admin.services.action', [$service, 'terminate']) }}" onsubmit="return confirm('Terminate this service? This cannot be undone.')">@csrf
                                    <button class="rounded-md bg-white px-3 py-1.5 text-red-700 ring-1 ring-red-300 hover:bg-red-50">Terminate</button>
                                </form>
                            @endif
                            @if ($canLogIn)
                                <form method="POST" action="{{ route('admin.services.control-panel', $service) }}" target="_blank">@csrf
                                    <button class="rounded-md bg-white px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Log in to the control panel</button>
                                </form>
                            @endif
                            @if ($module)
                                <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="flex items-center gap-2" onsubmit="return confirm('Run this action on {{ e($module->label()) }}?')">
                                    @csrf
                                    <label for="provision-action" class="sr-only">Module action</label>
                                    <select id="provision-action" name="action" class="rounded-md bg-white px-2 py-1.5 ring-1 ring-ink-300">
                                        @foreach (App\Provisioning\Provisioner::staffActions($module, $service) as $action)
                                            <option value="{{ $action }}" @selected($service->provisioning_action === $action)>{{ App\Provisioning\Provisioner::label($action) }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded-md bg-white px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Run again</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endcan
            </div>

            @if ($service->options->isNotEmpty() || $fields->isNotEmpty())
                <div class="mb-6 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
                    @foreach ($service->options->load(['option', 'choice'])->sortBy(fn ($chosen) => [$chosen->option->sort_order, $chosen->option->id]) as $chosen)
                        <div><div class="text-ink-500">{{ $chosen->option->name }}</div>{{ match ($chosen->option->type) { 'dropdown' => $chosen->choice->name, 'quantity' => $chosen->quantity, default => 'Yes' } }}</div>
                    @endforeach
                    @include('custom-fields._values', ['fields' => $fields, 'values' => $service->custom_fields ?? [], 'wide' => 'md:col-span-3', 'showAdminOnly' => true])
                </div>
            @endif

            @can('manage-clients')
                @if ($service->product->options->isNotEmpty() || $fields->isNotEmpty())
                    <details class="mb-6 text-sm" @if ($errors->hasAny(['options', 'custom_fields', 'custom_fields.*'])) open @endif>
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

                @if ($canCancel)
                    <details class="mb-6 text-sm" @if ($errors->has('cancellation')) open @endif>
                        <summary class="cursor-pointer text-brand-600">Request cancellation for the client</summary>
                        @include('services._cancel-form', ['action' => route('admin.cancellations.store', $service), 'periodEnd' => $service->next_due_date])
                    </details>
                @endif
            @endcan

            @if ($module)
                <div class="mb-6 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    <h2 class="mb-2 font-semibold">Provisioning: {{ $module->label() }}</h2>
                    @include('admin.services._move')
                    @if ($service->license)
                        <div class="mb-4 rounded-md border border-ink-100 p-3">
                            <h3 class="mb-2 font-medium">License</h3>
                            @include('services._license', ['license' => $service->license, 'staff' => true, 'reissue' => $staff ? route('admin.services.license.reissue', $service) : null])
                        </div>
                    @endif
                    @if ($service->currentUsage)
                        <div class="mb-4 rounded-md border border-ink-100 p-3">
                            <h3 class="mb-2 font-medium">Usage</h3>
                            @include('services._usage', ['usage' => $service->currentUsage])
                        </div>
                    @endif
                    @if ($service->provisioning_status)
                        <p>Last action: {{ $service->provisioning_action }}, <x-status-badge :status="$service->provisioning_status === 'done' ? 'active' : ($service->provisioning_status === 'failed' ? 'overdue' : 'pending')" /> {{ $service->provisioning_status }}@if ($service->provisioned_at), last success {{ $service->provisioned_at->format('M j, Y g:ia') }}@endif</p>
                        @if ($service->provisioning_error)<p class="mt-1 rounded-md bg-red-50 px-3 py-2 text-red-800">{{ $service->provisioning_error }}</p>@endif
                    @else
                        <p class="text-ink-500">Nothing has run yet. The module creates the service when it becomes active.</p>
                    @endif
                </div>
            @elseif ($service->openMove)
                {{-- Moved to a product without a module: the old account still needs finishing. --}}
                <div class="mb-6 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    @include('admin.services._move_status')
                </div>
            @endif

            @php $tasks = App\Models\FulfillmentTask::where('service_id', $service->id)->with(['service.client', 'service.product', 'completedBy'])->latest('id')->get(); @endphp
            @if ($tasks->isNotEmpty())
                <h2 class="mb-3 text-lg font-semibold">Tasks</h2>
                <div class="mb-6">@include('admin.tasks._table')</div>
            @endif

            <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
            @php $invoices = $service->invoiceItems->pluck('invoice')->unique('id')->sortByDesc('id')->each->setRelation('client', $client); @endphp
            @if ($invoices->isEmpty())
                <p class="text-sm text-ink-500">No invoices for this service yet.</p>
            @else
                @include('admin.invoices._table', ['invoices' => $invoices])
            @endif
        </div>
    </div>
</x-admin-layout>
