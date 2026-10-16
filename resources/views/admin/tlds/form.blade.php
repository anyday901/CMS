<x-admin-layout :title="$tld->exists ? 'Edit '.$tld->label() : 'Add TLD'">
    <p class="mb-2 text-sm"><a href="{{ route('admin.tlds.index') }}" class="text-brand-600">TLDs and pricing</a></p>
    <div class="mb-6 flex items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $tld->exists ? 'Edit '.$tld->label() : 'Add TLD' }}</h1>
        @if ($tld->exists)
            <form method="POST" action="{{ route('admin.tlds.destroy', $tld) }}" class="ml-auto" onsubmit="return confirm('Delete {{ $tld->label() }} and its prices?')">@csrf @method('DELETE')
                <button class="text-sm text-red-600 hover:underline">Delete</button>
            </form>
        @endif
    </div>

    <form method="POST" action="{{ $tld->exists ? route('admin.tlds.update', $tld) : route('admin.tlds.store') }}" class="max-w-4xl space-y-5 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @if ($tld->exists) @method('PUT') @endif
        <div class="grid gap-4 sm:grid-cols-3">
            <label class="block">Extension
                <input name="extension" value="{{ old('extension', $tld->extension) }}" required maxlength="63" placeholder="com" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Without the dot, such as com or co.uk.</span>
            </label>
            <label class="block">Registrar
                <select name="registrar" class="mt-1 w-full rounded-md bg-white px-3 py-2 ring-1 ring-ink-300">
                    <option value="">None (staff register by hand)</option>
                    @foreach ($registrars as $key => $registrar)
                        <option value="{{ $key }}" @selected(old('registrar', $tld->registrar) === $key)>{{ $registrar->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">Sort order
                <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $tld->sort_order ?? 0) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Lower comes first in searches.</span>
            </label>
        </div>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach (['active' => 'For sale in the store', 'epp_required' => 'Transfers need the EPP (auth) code', 'id_protection' => 'Offer ID protection (WHOIS privacy)', 'dns_management' => 'Clients can edit DNS records at the registrar'] as $field => $label)
                <input type="hidden" name="{{ $field }}" value="0">
                <label class="flex items-center gap-2"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $tld->{$field}))> {{ $label }}</label>
            @endforeach
        </div>

        <fieldset>
            <legend class="mb-1 font-semibold">Prices</legend>
            <p class="mb-3 text-ink-600">Leave a term blank to not sell it. ID protection is charged per term on top, and 0 makes it free. Transfers are sold for 1 year only, since the registrar adds one year to the domain.</p>
            @foreach ($currencies as $currency)
                <div class="mb-4 overflow-x-auto">
                    <table class="min-w-full">
                        <caption class="mb-1 text-left font-medium">{{ $currency }}</caption>
                        <thead class="text-left text-ink-500"><tr><th class="py-1 pr-3">Term</th><th class="py-1 pr-3">Register</th><th class="py-1 pr-3">Transfer</th><th class="py-1 pr-3">Renew</th><th class="py-1">ID protection</th></tr></thead>
                        <tbody>
                            @foreach ($terms as $years)
                                @php $price = $tld->exists ? $tld->priceFor($currency, $years) : null; @endphp
                                <tr>
                                    <td class="py-1 pr-3">{{ $years }} year{{ $years === 1 ? '' : 's' }}</td>
                                    @foreach (['register', 'transfer', 'renew', 'id_protection'] as $type)
                                        @php $value = old("prices.{$currency}.{$years}.{$type}", $price && $price->{$type} !== null ? App\Support\Money::toInput($price->{$type}) : ''); @endphp
                                        @if ($type === 'transfer' && $years !== 1)
                                            <td class="py-1 pr-3 text-ink-400">—</td>
                                            @continue
                                        @endif
                                        <td class="py-1 pr-3"><label class="sr-only" for="price-{{ $currency }}-{{ $years }}-{{ $type }}">{{ $currency }} {{ $years }} year {{ $type }}</label>
                                            <input id="price-{{ $currency }}-{{ $years }}-{{ $type }}" name="prices[{{ $currency }}][{{ $years }}][{{ $type }}]" value="{{ $value }}" inputmode="decimal" class="w-28 rounded-md px-2 py-1.5 ring-1 ring-ink-300"></td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </fieldset>

        <details>
            <summary class="cursor-pointer text-brand-600">WHOIS server (only when the registry has no RDAP service)</summary>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="block">Port 43 WHOIS server
                    <input name="whois_server" value="{{ old('whois_server', $tld->whois_server) }}" placeholder="whois.nic.example" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Text in the reply when the name is free
                    <input name="whois_available" value="{{ old('whois_available', $tld->whois_available) }}" placeholder="No match" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            </div>
            <p class="mt-2 text-ink-500">Lookups use the registry's RDAP service from IANA's list first; most extensions have one. Searches use the registrar's own check when it has credentials.</p>
        </details>

        <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">{{ $tld->exists ? 'Save' : 'Add TLD' }}</button>
    </form>
</x-admin-layout>
