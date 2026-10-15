@php use App\Support\Money; @endphp
<x-portal-layout :title="$service->description()">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $service->description() }}</h1>
        <x-status-badge :status="$service->status" />
        @if ($canUpgrade)
            <a href="{{ route('portal.services.upgrade', $service) }}" class="ml-auto rounded-full px-4 py-2 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Upgrade or downgrade</a>
        @endif
    </div>
    @if ($service->parent)
        <p class="mb-4 text-sm text-ink-600">An add-on for <a href="{{ route('portal.services.show', $service->parent) }}" class="text-brand-600">{{ $service->parent->description() }}</a>.</p>
    @endif
    @if ($service->pendingCancellation)
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">
            <span>This service will be cancelled on {{ $service->pendingCancellation->cancel_on->format('M j, Y') }} and won't be renewed.</span>
            @if ($canOrder)
                <form method="POST" action="{{ route('portal.services.cancel.destroy', $service) }}" class="ml-auto">@csrf @method('DELETE')
                    <button class="font-medium underline">Keep my service</button>
                </form>
            @endif
        </div>
    @endif
    @foreach ($service->upgrades as $upgrade)
        <p class="mb-4 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">Moving to {{ $upgrade->toProduct->name }} once
            @if ($upgrade->invoice)<a href="{{ route('portal.invoices.show', $upgrade->invoice) }}" class="font-medium underline">invoice #{{ $upgrade->invoice->number }}</a>@else its invoice @endif is paid.</p>
    @endforeach
    <div class="grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div><div class="text-ink-500">Price</div>{{ Money::format($service->netRecurring(), auth('client')->user()->currency) }} {{ strtolower($service->billing_cycle->label()) }}@if ($service->recurring_discount) <span class="text-ink-500">({{ Money::format($service->recurring_discount, auth('client')->user()->currency) }} off{{ $service->promotion ? ' with '.$service->promotion->code : '' }})</span>@endif</div>
        <div><div class="text-ink-500">Next due</div>{{ $service->next_due_date?->format('M j, Y') ?? '—' }}@if ($service->inTrial())<div class="text-brand-700">Free trial until then</div>@endif</div>
        <div><div class="text-ink-500">Started</div>{{ $service->registration_date->format('M j, Y') }}</div>
        @if ($service->optionSummary() !== '')<div class="sm:col-span-3"><div class="text-ink-500">Options</div>{{ $service->optionSummary() }}</div>@endif
        @include('custom-fields._values', ['fields' => $fields, 'values' => $service->custom_fields ?? [], 'wide' => 'sm:col-span-3'])
        @if ($service->product->description)<div class="sm:col-span-3"><div class="text-ink-500">About</div>{!! nl2br(e($service->product->description)) !!}</div>@endif
    </div>
    @if ($service->license)
        <h2 class="mt-8 mb-3 text-lg font-semibold">License</h2>
        <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">@include('services._license', ['license' => $service->license, 'reissue' => $canReissue ? route('portal.services.license.reissue', $service) : null])</div>
    @endif
    @if ($service->currentUsage)
        <h2 class="mt-8 mb-3 text-lg font-semibold">Usage</h2>
        <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">@include('services._usage', ['usage' => $service->currentUsage])</div>
    @endif
    @if ($service->openMove)
        <p class="mt-4 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">We're moving this service to a new server. Your current account keeps working until the move is finished, and we'll let you know if you need to do anything.</p>
    @endif
    @error('account')<p class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>@enderror
    @if ($login !== [])
        <div class="mt-8 mb-3 flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold">Logging in</h2>
            @if ($canLogIn)
                <form method="POST" action="{{ route('portal.services.control-panel', $service) }}" target="_blank" class="ml-auto">@csrf
                    <button class="rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Log in to the control panel</button>
                </form>
            @endif
        </div>
        @if ($service->provisioning_action === 'change_password' && $service->provisioning_status === 'failed')
            <p class="mb-3 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">Your last password change didn't go through, so the password below still works. Try again, or contact support if it keeps happening.</p>
        @elseif (filled($service->new_password))
            <p class="mb-3 rounded-md bg-brand-50 px-4 py-3 text-sm text-brand-800">Your new password is being set. Keep using the one below until it is.</p>
        @endif
        @if ($service->provisioning_action === 'reinstall' && $service->provisioning_status === 'pending')
            <p class="mb-3 rounded-md bg-brand-50 px-4 py-3 text-sm text-brand-800">Reinstalling. It can take a few minutes.</p>
        @elseif ($service->provisioning_action === 'reinstall' && $service->provisioning_status === 'failed')
            <p class="mb-3 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">The reinstall didn't finish. Our team has been told and will look into it.</p>
        @endif
        <dl class="grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
            @foreach ($login as $name => $value)
                <div><dt class="text-ink-500">{{ $name }}</dt><dd>@if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://'))<a href="{{ $value }}" class="text-brand-600" target="_blank" rel="noopener">{{ $value }}</a>@else<code class="break-all">{{ $value }}</code>@endif</dd></div>
            @endforeach
            @if (filled($service->password))
                <div><dt class="text-ink-500">Password</dt><dd><details><summary class="cursor-pointer text-brand-600">Show</summary><code class="break-all">{{ $service->password }}</code></details></dd></div>
            @endif
        </dl>
        @if ($canChangePassword)
            <details class="mt-4 text-sm" @if ($errors->has('password')) open @endif>
                <summary class="cursor-pointer text-brand-600">Change the password</summary>
                <form method="POST" action="{{ route('portal.services.password', $service) }}" class="mt-3 grid max-w-xl gap-3 rounded-lg border border-ink-200 bg-white p-4 sm:grid-cols-2">
                    @csrf @method('PUT')
                    <label class="block">New password
                        <input type="password" name="password" autocomplete="new-password" required class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    </label>
                    <label class="block">Type it again
                        <input type="password" name="password_confirmation" autocomplete="new-password" required class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    </label>
                    @error('password')<p class="text-red-700 sm:col-span-2">{{ $message }}</p>@enderror
                    <p class="text-ink-500 sm:col-span-2">At least 10 characters, with upper and lower case letters and a number. It replaces the control panel password{{ $service->username ? ' of '.$service->username : '' }}.</p>
                    <div class="sm:col-span-2"><button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Change password</button></div>
                </form>
            </details>
        @endif
        @if ($canReinstall)
            <details class="mt-4 text-sm" @if ($errors->has('confirm')) open @endif>
                <summary class="cursor-pointer text-ink-600">Reinstall</summary>
                <form method="POST" action="{{ route('portal.services.reinstall', $service) }}" class="mt-3 max-w-xl space-y-3 rounded-lg border border-red-200 bg-white p-4">
                    @csrf
                    <p>Reinstalling sets the server up again from scratch, with the same address and password. <strong>Everything on it is erased.</strong></p>
                    <label class="flex items-start gap-2"><input type="checkbox" name="confirm" value="1" class="mt-1"> <span>I understand everything on this server will be erased.</span></label>
                    @error('confirm')<p class="text-red-700">{{ $message }}</p>@enderror
                    <button class="rounded-full px-4 py-2 font-medium text-red-700 ring-1 ring-red-300 hover:bg-red-50">Reinstall</button>
                </form>
            </details>
        @endif
    @endif
    @if ($service->addons->isNotEmpty() || $addons->isNotEmpty())
        <h2 class="mt-8 mb-3 text-lg font-semibold">Add-ons</h2>
        @if ($service->addons->isNotEmpty())
            <div class="mb-4">@include('portal.services._table', ['services' => $service->addons])</div>
        @endif
        @if ($addons->isNotEmpty())
            <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                @foreach ($addons as $addon)
                    @php $price = $addon->priceFor($addon->addonCycleFor($service->billing_cycle, auth('client')->user()->currency), auth('client')->user()->currency); @endphp
                    <form method="POST" action="{{ route('cart.addon') }}" class="flex flex-wrap items-center gap-3 px-4 py-3">@csrf
                        <input type="hidden" name="service_id" value="{{ $service->id }}">
                        <input type="hidden" name="product_id" value="{{ $addon->id }}">
                        <div><div class="font-medium">{{ $addon->name }}</div>@if ($addon->description)<div class="text-ink-500">{{ $addon->description }}</div>@endif</div>
                        <span class="ml-auto">{{ Money::format($price->price, auth('client')->user()->currency) }} {{ strtolower($price->billing_cycle->label()) }}</span>
                        <button class="rounded-full bg-brand-600 px-3 py-1.5 font-medium text-white hover:bg-brand-500">Add to cart</button>
                    </form>
                @endforeach
            </div>
        @endif
    @endif
    @if ($canCancel)
        <details class="mt-8 text-sm" @if ($errors->has('cancellation')) open @endif>
            <summary class="cursor-pointer text-ink-600">Cancel this service</summary>
            @include('services._cancel-form', ['action' => route('portal.services.cancel', $service), 'periodEnd' => $service->next_due_date])
        </details>
    @endif
    @if ($service->status->value === 'suspended')
        <p class="mt-4 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">This service is suspended. If you have an unpaid invoice, paying it will restore the service.</p>
    @endif
</x-portal-layout>
