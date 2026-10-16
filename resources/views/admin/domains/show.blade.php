@php use App\Support\Money; $currency = $domain->client->currency; $canManage = auth()->user()->can('manage-clients'); @endphp
<x-admin-layout :title="$domain->name">
    <p class="mb-2 text-sm"><a href="{{ route('admin.domains.index') }}" class="text-brand-600">Domains</a> <span class="text-ink-400">/</span> <a href="{{ route('admin.clients.show', $domain->client) }}" class="text-brand-600">{{ $domain->client->fullName() }}</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $domain->name }}</h1>
        <x-status-badge :status="$domain->status">{{ $domain->status->label() }}</x-status-badge>
        <span class="text-sm text-ink-500">{{ $domain->type === 'transfer' ? 'Transferred in' : 'Registered' }} through {{ $registrar?->label() ?? ($domain->registrar ?: 'no registrar') }}</span>
    </div>

    @if ($domain->registrar_status === 'failed')
        <div class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800"><strong>{{ ucfirst((string) $domain->registrar_action) }} failed:</strong> {{ $domain->registrar_error }}</div>
    @elseif ($domain->registrar_status === 'pending')
        <div class="mb-4 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">{{ ucfirst((string) $domain->registrar_action) }} is queued for the registrar.</div>
    @endif
    @if ($registrar && ! $configured)
        <div class="mb-4 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">The {{ $registrar->label() }} credentials aren't entered yet. @can('manage-settings')<a href="{{ route('admin.domain-settings.edit') }}" class="font-medium underline">Enter them</a>.@endcan</div>
    @endif
    @if ($domain->sync_error)
        <div class="mb-4 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">Last sync failed {{ $domain->synced_at?->diffForHumans() }}: {{ $domain->sync_error }}</div>
    @endif
    @if (session('epp_code'))
        <div class="mb-4 rounded-md bg-brand-50 px-4 py-3 text-sm text-brand-800">EPP code: <code class="break-all">{{ session('epp_code') }}</code></div>
    @endif

    <div class="mb-6 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-4">
        <div><div class="text-ink-500">Expires</div>{{ $domain->expiry_date?->format('M j, Y') ?? '—' }}@if ($domain->daysLeft() !== null) <span class="text-ink-500">({{ $domain->daysLeft() >= 0 ? $domain->daysLeft().' days left' : abs($domain->daysLeft()).' days ago' }})</span>@endif</div>
        <div><div class="text-ink-500">Next renewal due</div>{{ $domain->next_due_date?->format('M j, Y') ?? '—' }}</div>
        <div><div class="text-ink-500">Renewal</div>{{ Money::format($domain->recurring_amount, $currency) }} for {{ $domain->years }} year{{ $domain->years === 1 ? '' : 's' }}, auto-renew {{ $domain->auto_renew ? 'on' : 'off' }}</div>
        <div><div class="text-ink-500">Last synced</div>{{ $domain->synced_at?->diffForHumans() ?? 'Never' }}</div>
        <div><div class="text-ink-500">Nameservers</div>{{ implode(', ', $domain->nameservers ?? []) ?: '—' }}</div>
        <div><div class="text-ink-500">ID protection</div>{{ $domain->id_protection ? 'On' : 'Off' }}</div>
        <div><div class="text-ink-500">Transfer lock</div>{{ $domain->locked === null ? 'Unknown' : ($domain->locked ? 'Locked' : 'Unlocked') }}</div>
        <div><div class="text-ink-500">Order</div>@if ($domain->order)<a href="{{ route('admin.orders.index', ['q' => $domain->order->id]) }}" class="text-brand-600">#{{ $domain->order->id }}</a>@else — @endif</div>
        @if ($openRenewal)<div class="sm:col-span-4"><div class="text-ink-500">Renewal invoice</div><a href="{{ route('admin.invoices.show', $openRenewal) }}" class="text-brand-600">#{{ $openRenewal->number }}</a> is unpaid</div>@endif
    </div>

    @if ($canManage && $registrar)
        <h2 class="mb-3 text-lg font-semibold">Registrar</h2>
        <div class="mb-8 flex flex-wrap items-end gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <form method="POST" action="{{ route('admin.domains.action', [$domain, 'sync']) }}">@csrf<button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Sync now</button></form>
            @if ($domain->status->value === 'pending')
                <form method="POST" action="{{ route('admin.domains.action', [$domain, $domain->type === 'transfer' ? 'transfer' : 'register']) }}" onsubmit="return confirm('Send this to {{ $registrar->label() }} now? The registrar charges your account.')">@csrf
                    <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $domain->type === 'transfer' ? 'Start the transfer' : 'Register now' }}</button></form>
            @endif
            @if (in_array($domain->status->value, ['active', 'expired'], true))
                <form method="POST" action="{{ route('admin.domains.action', [$domain, 'renew']) }}" class="flex items-end gap-2" onsubmit="return confirm('Renew at {{ $registrar->label() }} now, without an invoice? The registrar charges your account.')">@csrf
                    <label class="block">Years <input type="number" name="years" min="1" max="10" value="{{ $domain->years }}" class="mt-1 block w-20 rounded-md px-2 py-1.5 ring-1 ring-ink-300"></label>
                    <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Renew at the registrar</button>
                </form>
                <form method="POST" action="{{ route('admin.domains.epp-code', $domain) }}">@csrf<button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Get EPP code</button></form>
            @endif
        </div>

        @if ($domain->isManageable())
            <h2 class="mb-3 text-lg font-semibold">Nameservers</h2>
            <form method="POST" action="{{ route('admin.domains.nameservers', $domain) }}" class="mb-8 flex max-w-3xl flex-wrap items-end gap-2 rounded-lg border border-ink-200 bg-white p-4 text-sm">@csrf @method('PUT')
                @for ($i = 0; $i < App\Domains\DomainManager::MAX_NAMESERVERS; $i++)
                    <label class="block">NS{{ $i + 1 }}<input name="nameservers[]" value="{{ ($domain->nameservers ?? [])[$i] ?? '' }}" class="mt-1 block w-40 rounded-md px-2 py-1.5 ring-1 ring-ink-300"></label>
                @endfor
                <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save at the registrar</button>
            </form>
        @endif
    @endif

    @if ($canManage)
        <h2 class="mb-3 text-lg font-semibold">Details</h2>
        <form method="POST" action="{{ route('admin.domains.update', $domain) }}" class="mb-8 max-w-3xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">@csrf @method('PUT')
            <p class="text-ink-600">Changes here are saved in CMS only; the registrar isn't told. The sync overwrites the expiry date with the registrar's.</p>
            @include('admin.domains._fields')
            <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">Save</button>
        </form>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
    @if ($domain->invoiceItems->isEmpty())
        <p class="mb-8 text-sm text-ink-500">None yet.</p>
    @else
        <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Invoice</th><th class="px-4 py-2">Line</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($domain->invoiceItems->sortByDesc('id') as $item)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.invoices.show', $item->invoice) }}" class="text-brand-600">#{{ $item->invoice->number }}</a></td>
                            <td class="px-4 py-2">{{ $item->description }}</td>
                            <td class="px-4 py-2 text-right">{{ Money::format($item->amount, $item->invoice->currency) }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$item->invoice->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Activity</h2>
    @if ($activity->isEmpty())
        <p class="text-sm text-ink-500">Nothing yet.</p>
    @else
        <ul class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
            @foreach ($activity as $entry)
                <li class="flex flex-wrap gap-3 px-4 py-2"><span class="text-ink-500">{{ $entry->created_at->format('M j, Y H:i') }}</span><span>{{ $entry->description }}</span><span class="ml-auto text-ink-500">{{ $entry->actor_name }}</span></li>
            @endforeach
        </ul>
    @endif
</x-admin-layout>
