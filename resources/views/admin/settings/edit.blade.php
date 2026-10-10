<x-admin-layout title="Settings">
    <h1 class="mb-6 text-2xl font-semibold">Billing settings</h1>
    <form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-2xl space-y-6 rounded-lg border border-gray-200 bg-white p-6 text-sm">
        @csrf
        @method('PUT')

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Late fees</legend>
            <p class="text-gray-600">The nightly billing run adds one late fee to an invoice that is still unpaid this many days after its due date. Late fees are not taxed.</p>
            <label class="block">Days after the due date
                <input type="number" name="late_fee_after_days" min="0" max="365" value="{{ old('late_fee_after_days', $lateFeeDays) }}" placeholder="Off" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-gray-300">
                <span class="text-gray-500">Leave blank to turn late fees off.</span>
            </label>
            <div class="flex flex-wrap items-end gap-3">
                <label class="block">Fee type
                    <select name="late_fee_type" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-gray-300">
                        <option value="fixed" @selected(old('late_fee_type', $lateFeeType) === 'fixed')>Fixed amount</option>
                        <option value="percent" @selected(old('late_fee_type', $lateFeeType) === 'percent')>Percent of the invoice total</option>
                    </select>
                </label>
                <label class="block">Amount
                    <input name="late_fee_amount" value="{{ old('late_fee_amount', $lateFeeAmount) }}" placeholder="5.00 or 10" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-gray-300">
                </label>
            </div>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Tax</legend>
            <input type="hidden" name="tax_inclusive" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="tax_inclusive" value="1" class="mt-1" @checked(old('tax_inclusive', $taxInclusive))>
                <span>Prices include tax. Invoice totals equal the sum of their lines, and the invoice shows how much of that is tax. Applies to invoices created from now on.</span>
            </label>
        </fieldset>

        <button class="rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Save settings</button>
    </form>
</x-admin-layout>
