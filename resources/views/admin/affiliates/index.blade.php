@php use App\Support\Money; @endphp
<x-admin-layout title="Affiliates">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Affiliates</h1>
        <a href="{{ route('admin.affiliates.payouts') }}" class="ml-auto text-sm text-brand-600">Payout requests @if ($requested)<span class="rounded-full bg-sun-300/60 px-2 py-0.5 text-xs font-semibold text-amber-900">{{ $requested }}</span>@endif</a>
        @can('manage-settings')<a href="{{ route('admin.affiliates.settings') }}" class="text-sm text-brand-600">Settings</a>@endcan
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">
        Clients who share a referral link and earn a commission on what the clients they refer pay.
        @unless (config('affiliates.enabled'))
            <strong>The affiliate program is off</strong>, so links aren't tracked and no commissions are earned.
            @can('manage-settings')Turn it on under <a href="{{ route('admin.affiliates.settings') }}" class="text-brand-600">Settings</a>.@endcan
        @endunless
        To make a client an affiliate, use the Affiliate box on their page.
    </p>

    <form class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <label for="affiliate-search" class="sr-only">Search affiliates</label>
        <input id="affiliate-search" type="search" name="q" value="{{ $search }}" placeholder="Name, email or code" class="w-72 rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        <label for="affiliate-status" class="sr-only">Status</label>
        <select id="affiliate-status" name="status" class="rounded-md px-2 py-1.5 ring-1 ring-ink-300">
            <option value="">Any status</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="disabled" @selected($status === 'disabled')>Turned off</option>
        </select>
        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Search</button>
        @if ($search !== '' || $status)<a href="{{ route('admin.affiliates.index') }}" class="text-brand-600">Clear</a>@endif
    </form>

    @if ($affiliates->isEmpty())
        <p class="text-sm text-ink-500">{{ $search !== '' || $status ? 'No affiliates found.' : 'No affiliates yet.' }}</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500">
                    <tr><th class="px-4 py-2">Affiliate</th><th class="px-4 py-2">Code</th><th class="px-4 py-2">Status</th><th class="px-4 py-2 text-right">Visits</th><th class="px-4 py-2 text-right">Referrals</th><th class="px-4 py-2 text-right">Clearing</th><th class="px-4 py-2 text-right">Balance</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($affiliates as $affiliate)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.affiliates.show', $affiliate) }}" class="text-brand-600">{{ $affiliate->client->fullName() }}</a>
                                <div class="text-ink-500">{{ $affiliate->client->email }}</div></td>
                            <td class="px-4 py-2 font-mono">{{ $affiliate->code }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$affiliate->status">{{ $affiliate->isActive() ? 'Active' : 'Turned off' }}</x-status-badge></td>
                            <td class="px-4 py-2 text-right">{{ number_format($affiliate->visits) }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($affiliate->referrals_count) }}</td>
                            @foreach ([$affiliate->pendingTotals(), $affiliate->balances()] as $totals)
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ collect($totals)->map(fn ($amount, $currency) => Money::format($amount, $currency))->join(', ') ?: '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-500">Clearing: commissions waiting out their clearing days. Balance: approved commissions not yet paid or asked for.</p>
        <div class="mt-4">{{ $affiliates->links() }}</div>
    @endif
</x-admin-layout>
