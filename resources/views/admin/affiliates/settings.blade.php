@php $input = 'mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout title="Affiliate settings">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Affiliate settings</h1>
        <a href="{{ route('admin.affiliates.index') }}" class="ml-auto text-sm text-brand-600">Affiliates</a>
        <a href="{{ route('admin.settings.edit') }}" class="text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Affiliates share a referral link. A visitor who follows it and signs up at checkout becomes their referral, and the affiliate earns a commission on what that client pays. Commissions wait out the clearing days in case of a refund, then count towards a balance the affiliate can ask to have paid.</p>

    <form method="POST" action="{{ route('admin.affiliates.settings.update') }}" class="max-w-2xl space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @method('PUT')

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Program</legend>
            <input type="hidden" name="enabled" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="enabled" value="1" class="mt-1" @checked(old('enabled', $enabled))>
                <span>Run the affiliate program. When off, links aren't tracked and no commissions are earned; balances already earned can still be paid.</span>
            </label>
            <input type="hidden" name="self_signup" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="self_signup" value="1" class="mt-1" @checked(old('self_signup', $selfSignup))>
                <span>Clients can join from the portal. When off, staff make clients affiliates from their page.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Commission</legend>
            <div class="flex flex-wrap items-end gap-3">
                <label class="block">Type
                    <select name="commission_type" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="percent" @selected(old('commission_type', $commissionType) === 'percent')>Percent of each invoice before tax</option>
                        <option value="fixed" @selected(old('commission_type', $commissionType) === 'fixed')>Fixed amount per invoice</option>
                    </select>
                </label>
                <label class="block">Amount
                    <input name="commission_amount" value="{{ old('commission_amount', $commissionAmount) }}" required placeholder="10 or 5.00" class="{{ $input }}">
                </label>
            </div>
            <p class="text-ink-500">A fixed amount is paid in the invoice's currency. You can give one affiliate their own rate on their page.</p>
            <input type="hidden" name="first_invoice_only" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="first_invoice_only" value="1" class="mt-1" @checked(old('first_invoice_only', $firstInvoiceOnly))>
                <span>Pay only on a referred client's first paid invoice, instead of every one.</span>
            </label>
            <label class="block">Stop paying this many months after the referral
                <input type="number" name="months" min="1" max="600" value="{{ old('months', $months) }}" placeholder="Never" class="{{ $input }}">
                <span class="text-ink-500">Leave blank to pay for as long as the client stays.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Clearing and payouts</legend>
            <label class="block">Days before a commission is approved
                <input type="number" name="clearing_days" min="0" max="365" required value="{{ old('clearing_days', $clearingDays) }}" class="{{ $input }}">
                <span class="text-ink-500">A refund in this time lowers or voids it. 0 approves commissions at once.</span>
            </label>
            <label class="block">Minimum payout
                <input name="minimum_payout" value="{{ old('minimum_payout', $minimumPayout) }}" required placeholder="25.00" class="{{ $input }}">
                <span class="text-ink-500">The balance an affiliate needs before they can ask for a payout, in the balance's currency.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Referral link</legend>
            <label class="block">Remember a referral link for this many days
                <input type="number" name="cookie_days" min="1" max="3650" required value="{{ old('cookie_days', $cookieDays) }}" class="{{ $input }}">
                <span class="text-ink-500">A visitor who signs up within this time, in the same browser, counts as the affiliate's referral.</span>
            </label>
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save affiliate settings</button>
    </form>
</x-admin-layout>
