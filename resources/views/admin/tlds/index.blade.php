@php use App\Support\Money; @endphp
<x-admin-layout title="TLDs and pricing">
    <p class="mb-2 text-sm"><a href="{{ route('admin.settings.edit') }}" class="text-brand-600">Settings</a> <span class="text-ink-400">/</span> <a href="{{ route('admin.domains.index') }}" class="text-brand-600">Domains</a></p>
    <div class="mb-2 flex items-center gap-3">
        <h1 class="text-2xl font-semibold">TLDs and pricing</h1>
        <a href="{{ route('admin.domain-settings.edit') }}" class="ml-auto rounded-full px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Registrars</a>
        <a href="{{ route('admin.tlds.create') }}" class="rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add TLD</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">The domain extensions clients can register and transfer in the store, each with the registrar that handles it and a price for each term and currency. Prices below are the one year {{ $currency }} prices.</p>

    @if ($tlds->isEmpty())
        <p class="text-sm text-ink-500">No TLDs yet. Add one, such as com, to start selling domains.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">TLD</th><th class="px-4 py-2">Registrar</th><th class="px-4 py-2 text-right">Register</th><th class="px-4 py-2 text-right">Transfer</th><th class="px-4 py-2 text-right">Renew</th><th class="px-4 py-2">Extras</th><th class="px-4 py-2">Domains</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($tlds as $tld)
                        @php $price = $tld->priceFor($currency, 1); @endphp
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.tlds.edit', $tld) }}" class="font-medium text-brand-600">{{ $tld->label() }}</a></td>
                            <td class="px-4 py-2">{{ $registrars->get($tld->registrar)?->label() ?? 'None' }}</td>
                            @foreach (['register', 'transfer', 'renew'] as $type)
                                <td class="px-4 py-2 text-right">{{ $price?->{$type} !== null && $price ? Money::format($price->{$type}, $currency) : '—' }}</td>
                            @endforeach
                            <td class="px-4 py-2 text-ink-600">{{ collect([$tld->id_protection ? 'ID protection' : null, $tld->dns_management ? 'DNS' : null, $tld->epp_required ? 'EPP code' : null])->filter()->join(', ') ?: '—' }}</td>
                            <td class="px-4 py-2">{{ $tld->domains_count }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$tld->active ? 'active' : 'inactive'">{{ $tld->active ? 'For sale' : 'Hidden' }}</x-status-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin-layout>
