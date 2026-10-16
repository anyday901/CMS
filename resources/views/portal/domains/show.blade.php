@php use App\Support\Money; $currency = auth('client')->user()->currency; $manageable = $domain->isManageable(); @endphp
<x-portal-layout :title="$domain->name">
    <p class="mb-2 text-sm"><a href="{{ route('portal.domains.index') }}" class="text-brand-600">{{ __('Domains') }}</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $domain->name }}</h1>
        <x-status-badge :status="$domain->status">{{ __($domain->status->label()) }}</x-status-badge>
    </div>

    @if ($domain->status->value === 'pending')
        <p class="mb-4 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">{{ $domain->type === 'transfer' ? __('We start the transfer once the order is paid.') : __('We register the domain once the order is paid. It usually takes a few minutes.') }}</p>
    @elseif ($domain->status->value === 'pending_transfer')
        <p class="mb-4 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">{{ __('The transfer is in progress. It usually takes five to seven days; the current registrar may email the registrant to approve it.') }}</p>
    @elseif ($domain->status->value === 'expired')
        <p class="mb-4 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">{{ __('This domain has expired. Renew it now to keep it: after a short grace period it can be lost.') }}</p>
    @endif

    <div class="grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-4">
        <div><div class="text-ink-500">{{ __('Registered') }}</div>{{ $domain->registration_date?->translatedFormat('M j, Y') ?? '—' }}</div>
        <div><div class="text-ink-500">{{ __('Expires') }}</div>{{ $domain->expiry_date?->translatedFormat('M j, Y') ?? '—' }}</div>
        <div><div class="text-ink-500">{{ __('Renewal') }}</div>{{ Money::format($domain->recurring_amount, $currency) }} {{ trans_choice('every year|every :count years', $domain->years) }}</div>
        <div><div class="text-ink-500">{{ __('ID protection') }}</div>{{ $domain->id_protection ? __('On') : __('Off') }}</div>
    </div>

    @if (in_array($domain->status->value, ['active', 'expired'], true))
        <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Renewal') }}</h2>
        <div class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @if ($renewalPaid)
                <p class="text-brand-700">{{ __('The renewal is paid. We are renewing it with the registrar.') }}</p>
            @elseif ($openRenewal)
                <p>{!! __('The renewal invoice is waiting to be paid: :invoice.', ['invoice' => App\Auth\PortalContact::allows('billing') ? '<a href="'.e(route('portal.invoices.show', $openRenewal)).'" class="font-medium text-brand-600">'.e(__('invoice #:number', ['number' => $openRenewal->number])).'</a>' : e(__('invoice #:number', ['number' => $openRenewal->number]))]) !!}</p>
            @endif
            @if ($canOrder)
                <form method="POST" action="{{ route('portal.domains.auto-renew', $domain) }}" class="flex flex-wrap items-center gap-3">@csrf @method('PUT')
                    <input type="hidden" name="auto_renew" value="{{ $domain->auto_renew ? 0 : 1 }}">
                    <span>{{ $domain->auto_renew ? __("Auto-renew is on: we send the renewal invoice before the domain expires.") : __("Auto-renew is off: the domain expires unless you renew it here.") }}</span>
                    <button class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ $domain->auto_renew ? __('Turn auto-renew off') : __('Turn auto-renew on') }}</button>
                </form>
                @if (! $openRenewal && ! $renewalPaid && $renewTerms->isNotEmpty())
                    <form method="POST" action="{{ route('portal.domains.renew', $domain) }}" class="flex flex-wrap items-center gap-3 border-t border-ink-100 pt-3">@csrf
                        <label for="renew-years">{{ __('Renew now for') }}</label>
                        <select id="renew-years" name="years" class="rounded-md bg-white px-2 py-1.5 ring-1 ring-ink-300">
                            @foreach ($renewTerms as $term)
                                <option value="{{ $term->years }}" @selected($term->years === $domain->years)>{{ trans_choice(':count year|:count years', $term->years) }}: {{ Money::format($term->years === $domain->years ? $domain->recurring_amount : $term->renew + ($domain->id_protection ? $term->id_protection : 0), $currency) }}</option>
                            @endforeach
                        </select>
                        <button class="rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">{{ __('Renew') }}</button>
                    </form>
                @endif
                @error('renew')<p class="text-red-700">{{ $message }}</p>@enderror
            @endif
        </div>
    @endif

    @if ($manageable)
        <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Nameservers') }}</h2>
        <form method="POST" action="{{ route('portal.domains.nameservers', $domain) }}" class="max-w-xl space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">@csrf @method('PUT')
            <p class="text-ink-600">{{ __('The servers that answer for this domain. Use your host\'s nameservers, or ours to keep using DNS records below.') }}</p>
            @php $nameservers = old('nameservers', $domain->nameservers ?? []); @endphp
            @for ($i = 0; $i < App\Domains\DomainManager::MAX_NAMESERVERS; $i++)
                <label class="block">{{ __('Nameserver :number', ['number' => $i + 1]) }}
                    <input name="nameservers[]" value="{{ $nameservers[$i] ?? '' }}" maxlength="253" @if ($i < 2) required @endif placeholder="ns{{ $i + 1 }}.example.com" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            @endfor
            @error('nameservers')<p class="text-red-700">{{ $message }}</p>@enderror
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Save nameservers') }}</button>
        </form>

        @if ($hasDns)
            <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('DNS records') }}</h2>
            @if ($dnsError)
                <p class="mb-3 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">{{ __("We couldn't load the DNS records right now. Please try again later.") }}</p>
            @else
                <form method="POST" action="{{ route('portal.domains.dns', $domain) }}" class="rounded-lg border border-ink-200 bg-white p-4 text-sm">@csrf @method('PUT')
                    <p class="mb-3 text-ink-600">{{ __('These work while the domain uses our nameservers. Empty a row\'s value to delete it.') }}</p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="text-left text-ink-500"><tr><th class="py-1 pr-2">{{ __('Host') }}</th><th class="py-1 pr-2">{{ __('Type') }}</th><th class="py-1 pr-2">{{ __('Value') }}</th><th class="py-1">{{ __('Priority') }}</th></tr></thead>
                            <tbody>
                                @foreach ([...$records, ...array_fill(0, 3, null)] as $i => $record)
                                    <tr>
                                        <td class="py-1 pr-2"><label class="sr-only" for="dns-host-{{ $i }}">{{ __('Host') }}</label><input id="dns-host-{{ $i }}" name="records[{{ $i }}][host]" value="{{ $record?->host }}" placeholder="@" maxlength="253" class="w-28 rounded-md px-2 py-1.5 ring-1 ring-ink-300"></td>
                                        <td class="py-1 pr-2"><label class="sr-only" for="dns-type-{{ $i }}">{{ __('Type') }}</label><select id="dns-type-{{ $i }}" name="records[{{ $i }}][type]" class="rounded-md bg-white px-2 py-1.5 ring-1 ring-ink-300"><option value=""></option>@foreach (App\Domains\DnsRecord::TYPES as $type)<option @selected($record?->type === $type)>{{ $type }}</option>@endforeach</select></td>
                                        <td class="py-1 pr-2"><label class="sr-only" for="dns-value-{{ $i }}">{{ __('Value') }}</label><input id="dns-value-{{ $i }}" name="records[{{ $i }}][value]" value="{{ $record?->value }}" maxlength="1000" class="w-full min-w-56 rounded-md px-2 py-1.5 font-mono ring-1 ring-ink-300"></td>
                                        <td class="py-1"><label class="sr-only" for="dns-priority-{{ $i }}">{{ __('Priority') }}</label><input id="dns-priority-{{ $i }}" type="number" min="0" max="65535" name="records[{{ $i }}][priority]" value="{{ $record?->priority }}" placeholder="MX" class="w-20 rounded-md px-2 py-1.5 ring-1 ring-ink-300"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @error('dns')<p class="mt-2 text-red-700">{{ $message }}</p>@enderror
                    <button class="mt-3 rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Save DNS records') }}</button>
                </form>
            @endif
        @endif

        <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Registrant contact') }}</h2>
        <details class="text-sm" @if ($errors->has('contact') || $errors->has('first_name') || $errors->has('email')) open @endif>
            <summary class="cursor-pointer text-brand-600">{{ __(':name, :email', ['name' => trim($registrant->firstName.' '.$registrant->lastName), 'email' => $registrant->email]) }}</summary>
            <form method="POST" action="{{ route('portal.domains.contact', $domain) }}" class="mt-3 grid max-w-2xl gap-3 rounded-lg border border-ink-200 bg-white p-4 sm:grid-cols-2">@csrf @method('PUT')
                <p class="text-ink-600 sm:col-span-2">{{ __('The owner of the domain, as the registrar records it. Some registries email the old and new owner to confirm a change.') }}</p>
                @foreach (['first_name' => __('First name'), 'last_name' => __('Last name'), 'company' => __('Company'), 'email' => __('Email'), 'phone' => __('Phone'), 'address1' => __('Address'), 'address2' => __('Address line 2'), 'city' => __('City'), 'state' => __('State'), 'postcode' => __('ZIP / postcode'), 'country' => __('Country (2-letter code)')] as $field => $label)
                    <label class="block">{{ $label }}
                        <input name="{{ $field }}" value="{{ old($field, $registrant->toArray()[$field]) }}" @if (! in_array($field, ['company', 'address2', 'state'], true)) required @endif class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    </label>
                @endforeach
                @error('contact')<p class="text-red-700 sm:col-span-2">{{ $message }}</p>@enderror
                <div class="sm:col-span-2"><button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Save contact') }}</button></div>
            </form>
        </details>

        <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Security') }}</h2>
        <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
            @if ($domain->tld?->id_protection)
                <form method="POST" action="{{ route('portal.domains.id-protection', $domain) }}" class="flex flex-wrap items-center gap-3 px-4 py-3">@csrf @method('PUT')
                    <input type="hidden" name="id_protection" value="{{ $domain->id_protection ? 0 : 1 }}">
                    <div class="grow"><div class="font-medium">{{ __('ID protection') }}</div><div class="text-ink-500">{{ __('Hides your name, address and email from the public WHOIS record.') }}@if (! $domain->id_protection && $protectionPrice > 0) {{ __('Costs :amount, added to your next renewal.', ['amount' => Money::format($protectionPrice, $currency)]) }}@endif</div></div>
                    <button class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ $domain->id_protection ? __('Turn off') : __('Turn on') }}</button>
                    @error('id_protection')<p class="w-full text-red-700">{{ $message }}</p>@enderror
                </form>
            @endif
            <form method="POST" action="{{ route('portal.domains.lock', $domain) }}" class="flex flex-wrap items-center gap-3 px-4 py-3">@csrf @method('PUT')
                <input type="hidden" name="locked" value="{{ $domain->locked === false ? 1 : 0 }}">
                <div class="grow"><div class="font-medium">{{ __('Transfer lock') }}</div><div class="text-ink-500">{{ $domain->locked === false ? __('Unlocked: the domain can be transferred to another registrar.') : __('Locked: nobody can transfer the domain away. Unlock it only to move it.') }}</div></div>
                <button class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ $domain->locked === false ? __('Lock') : __('Unlock') }}</button>
                @error('lock')<p class="w-full text-red-700">{{ $message }}</p>@enderror
            </form>
            <form method="POST" action="{{ route('portal.domains.epp-code', $domain) }}" class="flex flex-wrap items-center gap-3 px-4 py-3">@csrf
                <div class="grow"><div class="font-medium">{{ __('EPP code') }}</div><div class="text-ink-500">{{ __('The code another registrar asks for to transfer the domain away.') }}</div>
                    @if (session('epp_code'))<div class="mt-1"><code class="break-all rounded bg-ink-50 px-2 py-1">{{ session('epp_code') }}</code></div>@endif
                </div>
                <button class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Get EPP code') }}</button>
                @error('epp')<p class="w-full text-red-700">{{ $message }}</p>@enderror
            </form>
        </div>
    @endif
</x-portal-layout>
