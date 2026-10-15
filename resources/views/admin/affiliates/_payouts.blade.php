@php use App\Models\AffiliatePayout; use App\Support\Money; @endphp
<div class="space-y-3">
    @foreach ($payouts as $payout)
        <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <div class="flex flex-wrap items-center gap-3">
                <span class="font-semibold">#{{ $payout->id }} · {{ Money::format($payout->amount, $payout->currency) }}</span>
                <x-status-badge :status="$payout->status" />
                @if ($showAffiliate)
                    <a href="{{ route('admin.affiliates.show', $payout->affiliate) }}" class="text-brand-600">{{ $payout->affiliate->client->fullName() }}</a>
                @endif
                <span class="ml-auto text-ink-500">Asked for {{ $payout->created_at->format('M j, Y') }}</span>
            </div>
            @if ($payout->status === AffiliatePayout::PAID)
                <p class="mt-1 text-ink-600">{{ $payout->methodLabel() }}@if ($payout->reference), reference {{ $payout->reference }}@endif · {{ $payout->paid_at?->format('M j, Y') }}@if ($payout->user) by {{ $payout->user->name }}@endif</p>
            @elseif ($payout->status === AffiliatePayout::REJECTED)
                <p class="mt-1 text-ink-600">Rejected{{ $payout->user ? ' by '.$payout->user->name : '' }}{{ $payout->notes ? ': '.$payout->notes : '' }}</p>
            @else
                <div class="mt-3 flex flex-wrap items-end gap-4">
                    @if ($payout->currency === $payout->affiliate->client->currency)
                        <form method="POST" action="{{ route('admin.affiliates.payouts.pay', $payout) }}">@csrf
                            <input type="hidden" name="method" value="credit">
                            <button class="rounded-full bg-brand-600 px-3 py-1.5 font-medium text-white hover:bg-brand-500">Pay as account credit</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.affiliates.payouts.pay', $payout) }}" class="flex flex-wrap items-end gap-2">@csrf
                        <input type="hidden" name="method" value="manual">
                        <label class="block">Paid outside the app, reference
                            <input name="reference" required maxlength="255" placeholder="PayPal or bank transaction id" class="mt-1 block w-64 rounded-md px-2 py-1 ring-1 ring-ink-300">
                        </label>
                        <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Mark paid</button>
                    </form>
                    <form method="POST" action="{{ route('admin.affiliates.payouts.reject', $payout) }}" class="flex flex-wrap items-end gap-2" onsubmit="return confirm('Reject this payout request? The amount goes back to the affiliate\'s balance.')">@csrf
                        <label class="block">Reason (optional)
                            <input name="reason" maxlength="1000" class="mt-1 block w-48 rounded-md px-2 py-1 ring-1 ring-ink-300">
                        </label>
                        <button class="text-red-600 hover:underline">Reject</button>
                    </form>
                </div>
                @if ($payout->currency !== $payout->affiliate->client->currency)
                    <p class="mt-2 text-ink-500">This payout is in {{ $payout->currency }} and the affiliate is billed in {{ $payout->affiliate->client->currency }}, so it can only be paid outside the app.</p>
                @endif
            @endif
        </div>
    @endforeach
</div>
