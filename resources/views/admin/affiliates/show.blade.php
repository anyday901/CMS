@php
    use App\Models\Affiliate;
    use App\Models\AffiliateCommission;
    use App\Support\Money;
    $money = fn (array $totals) => collect($totals)->map(fn ($amount, $currency) => Money::format($amount, $currency))->join(', ') ?: Money::format(0, $affiliate->client->currency);
@endphp
<x-admin-layout :title="'Affiliate: '.$affiliate->client->fullName()">
    <p class="mb-2 text-sm"><a href="{{ route('admin.affiliates.index') }}" class="text-brand-600">Affiliates</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $affiliate->client->fullName() }}</h1>
        <x-status-badge :status="$affiliate->status">{{ $affiliate->isActive() ? 'Active' : 'Turned off' }}</x-status-badge>
        <a href="{{ route('admin.clients.show', $affiliate->client) }}" class="ml-auto text-sm text-brand-600">Client page</a>
    </div>

    <div class="mb-8 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
        <div class="md:col-span-3"><div class="text-ink-500">Referral link</div><span class="font-mono">{{ $affiliate->link() }}</span></div>
        <div><div class="text-ink-500">Commission</div>{{ $affiliate->commissionLabel() }}@if ($affiliate->commission_type === null) <span class="text-ink-500">(the program's)</span>@endif</div>
        <div><div class="text-ink-500">Visits</div>{{ number_format($affiliate->visits) }}</div>
        <div><div class="text-ink-500">Referrals</div>{{ number_format($referrals->count()) }}</div>
        <div><div class="text-ink-500">Clearing</div>{{ $money($pending) }}</div>
        <div><div class="text-ink-500">Balance</div>{{ $money($balances) }}</div>
        <div><div class="text-ink-500">Affiliate since</div>{{ $affiliate->created_at->format('M j, Y') }}</div>
    </div>

    <details class="mb-8 text-sm" @if ($errors->hasAny(['status', 'commission_type', 'commission_amount'])) open @endif>
        <summary class="cursor-pointer text-brand-600">Edit affiliate</summary>
        <form method="POST" action="{{ route('admin.affiliates.update', $affiliate) }}" class="mt-2 flex flex-wrap items-end gap-3 rounded-lg border border-ink-200 bg-white p-4">
            @csrf @method('PUT')
            <label class="block">Status
                <select name="status" class="mt-1 block rounded-md px-2 py-1 ring-1 ring-ink-300">
                    <option value="active" @selected(old('status', $affiliate->status) === 'active')>Active</option>
                    <option value="disabled" @selected(old('status', $affiliate->status) === 'disabled')>Turned off</option>
                </select>
            </label>
            <label class="block">Commission
                <select name="commission_type" class="mt-1 block rounded-md px-2 py-1 ring-1 ring-ink-300">
                    <option value="">The program's ({{ (new Affiliate)->commissionLabel() }})</option>
                    <option value="percent" @selected(old('commission_type', $affiliate->commission_type) === 'percent')>Percent of each invoice</option>
                    <option value="fixed" @selected(old('commission_type', $affiliate->commission_type) === 'fixed')>Fixed amount per invoice</option>
                </select>
            </label>
            <label class="block">Amount
                <input name="commission_amount" value="{{ old('commission_amount', $affiliate->commission_value !== null ? Money::toInput($affiliate->commission_value) : '') }}" placeholder="10 or 5.00" class="mt-1 block w-28 rounded-md px-2 py-1 ring-1 ring-ink-300">
            </label>
            <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Save</button>
            <span class="basis-full text-ink-500">A turned-off affiliate's link isn't tracked, they earn no new commissions and can't ask for payouts.</span>
        </form>
    </details>

    <h2 class="mb-3 text-lg font-semibold">Referrals</h2>
    @if ($referrals->isEmpty())
        <p class="mb-8 text-sm text-ink-500">No referrals yet. Set a client's referrer on their page.</p>
    @else
        <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Client</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Referred</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($referrals as $referral)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $referral->client) }}" class="text-brand-600">{{ $referral->client->fullName() }}</a></td>
                            <td class="px-4 py-2"><x-status-badge :status="$referral->client->status" /></td>
                            <td class="px-4 py-2">{{ $referral->created_at->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Commissions</h2>
    @if ($commissions->isEmpty())
        <p class="mb-8 text-sm text-ink-500">No commissions yet.</p>
    @else
        <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">For</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($commissions as $commission)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ $commission->created_at->format('M j, Y') }}</td>
                            <td class="px-4 py-2">
                                @if ($commission->invoice)<a href="{{ route('admin.invoices.show', $commission->invoice) }}" class="text-brand-600">Invoice #{{ $commission->invoice->number }}</a>@else{{ $commission->description ?? '—' }}@endif
                                @if ($commission->referral?->client)<span class="text-ink-500">· {{ $commission->referral->client->fullName() }}</span>@endif
                                @if ($commission->amount !== $commission->original_amount)<div class="text-xs text-ink-500">Lowered from {{ Money::format($commission->original_amount, $commission->currency) }} after a refund</div>@endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">{{ Money::format($commission->amount, $commission->currency) }}</td>
                            <td class="px-4 py-2">
                                <x-status-badge :status="$commission->status" />
                                @if ($commission->status === AffiliateCommission::PENDING && $commission->available_at)<div class="text-xs text-ink-500">Clears {{ $commission->available_at->format('M j, Y') }}</div>@endif
                                @if ($commission->status === AffiliateCommission::APPROVED && $commission->payout_id)<div class="text-xs text-ink-500">In payout request #{{ $commission->payout_id }}</div>@endif
                                @if ($commission->void_reason)<div class="text-xs text-ink-500">{{ $commission->void_reason }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                @if ($commission->status === AffiliateCommission::PENDING)
                                    <form method="POST" action="{{ route('admin.affiliates.commissions.approve', $commission) }}" class="inline">@csrf
                                        <button class="text-brand-600 hover:underline">Approve</button>
                                    </form>
                                @endif
                                @if ($commission->canBeVoided())
                                    <form method="POST" action="{{ route('admin.affiliates.commissions.void', $commission) }}" class="ml-2 inline" onsubmit="return confirm('Void this commission? The affiliate won\'t be paid it.')">@csrf
                                        <button class="text-red-600 hover:underline">Void</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Payouts</h2>
    @if ($payouts->isEmpty())
        <p class="text-sm text-ink-500">No payouts yet.</p>
    @else
        @include('admin.affiliates._payouts', ['payouts' => $payouts, 'showAffiliate' => false])
    @endif
</x-admin-layout>
