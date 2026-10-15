<x-admin-layout title="Affiliate payouts">
    <p class="mb-2 text-sm"><a href="{{ route('admin.affiliates.index') }}" class="text-brand-600">Affiliates</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Payout requests</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Affiliates ask for their approved balance here once it reaches the minimum payout. Pay it as account credit, which they can spend on their invoices, or send the money yourself (PayPal, bank transfer) and record the reference. Rejecting puts the amount back in their balance.</p>

    @if ($requested->isEmpty())
        <p class="mb-8 text-sm text-ink-500">No payout requests waiting.</p>
    @else
        <div class="mb-8">@include('admin.affiliates._payouts', ['payouts' => $requested, 'showAffiliate' => true])</div>
    @endif

    @if ($recent->isNotEmpty())
        <h2 class="mb-3 text-lg font-semibold">Recently paid or rejected</h2>
        @include('admin.affiliates._payouts', ['payouts' => $recent, 'showAffiliate' => true])
    @endif
</x-admin-layout>
