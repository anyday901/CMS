@php $input = 'mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout title="Automation settings">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Automation settings</h1>
        <a href="{{ route('admin.cron.show') }}" class="ml-auto text-sm text-brand-600">Cron status</a>
        <a href="{{ route('admin.settings.edit') }}" class="text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Once a day the billing run carries out cancellations, creates renewal and overage invoices, charges saved cards, adds late fees, sends overdue reminders, and suspends and terminates overdue services, in that order. These are the days it works by.</p>

    <form method="POST" action="{{ route('admin.automation.update') }}" class="max-w-2xl space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @method('PUT')

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Billing run</legend>
            <label class="block">Time of day
                <input type="time" name="run_time" value="{{ old('run_time', $runTime) }}" required class="{{ $input }} w-32">
                <span class="text-ink-500">In the server's timezone ({{ $timezone }}). It needs the cron entry; see <a href="{{ route('admin.cron.show') }}" class="text-brand-600">Cron status</a>.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Invoices</legend>
            <label class="block">Create renewal invoices this many days before the due date
                <input type="number" name="invoice_days_before_due" min="0" max="365" required value="{{ old('invoice_days_before_due', $invoiceDays) }}" class="{{ $input }}">
            </label>
            <label class="block">Days clients have to pay a usage overage invoice
                <input type="number" name="overage_due_days" min="0" max="365" required value="{{ old('overage_due_days', $overageDays) }}" class="{{ $input }}">
            </label>
            <input type="hidden" name="apply_credit_automatically" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="apply_credit_automatically" value="1" class="mt-1" @checked(old('apply_credit_automatically', $applyCredit))>
                <span>Pay new invoices from the client's credit balance when they are created.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Automatic card charging</legend>
            <p class="text-ink-600">Unpaid invoices are charged to the client's default saved card, for clients who saved one and haven't turned automatic charging off. It needs a gateway that saves cards, such as Stripe, under <a href="{{ route('admin.payment-gateways.edit') }}" class="text-brand-600">Payment gateways</a>. A failed charge emails the client the "Payment failed" email, and shows on the invoice.</p>
            <label class="block">Charge this many days before the due date
                <input type="number" name="auto_charge_days_before_due" min="0" max="60" value="{{ old('auto_charge_days_before_due', $autoChargeDays) }}" class="{{ $input }}">
                <span class="text-ink-500">0 charges on the due date.</span>
            </label>
            <label class="block">Try a failed charge again this many days after the due date
                <input name="charge_retry_days" value="{{ old('charge_retry_days', $chargeRetryDays) }}" placeholder="No retries" class="{{ $input }} w-48">
                <span class="text-ink-500">One try for each number, such as <code class="rounded bg-ink-100 px-1">1, 3, 5</code>. Leave blank to try only once. An invoice is never charged automatically twice on one day.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Overdue reminders</legend>
            <label class="block">Send a reminder this many days after the due date
                <input name="reminder_days" value="{{ old('reminder_days', $reminderDays) }}" placeholder="Off" class="{{ $input }} w-48">
                <span class="text-ink-500">One reminder for each number, such as <code class="rounded bg-ink-100 px-1">1, 7, 14</code>. Leave blank to send none.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Late fees</legend>
            <p class="text-ink-600">One late fee is added to an invoice that is still unpaid this many days after its due date. Late fees are not taxed.</p>
            <label class="block">Days after the due date
                <input type="number" name="late_fee_after_days" min="0" max="365" value="{{ old('late_fee_after_days', $lateFeeDays) }}" placeholder="Off" class="{{ $input }}">
                <span class="text-ink-500">Leave blank to turn late fees off.</span>
            </label>
            <div class="flex flex-wrap items-end gap-3">
                <label class="block">Fee type
                    <select name="late_fee_type" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="fixed" @selected(old('late_fee_type', $lateFeeType) === 'fixed')>Fixed amount</option>
                        <option value="percent" @selected(old('late_fee_type', $lateFeeType) === 'percent')>Percent of the invoice total</option>
                    </select>
                </label>
                <label class="block">Amount
                    <input name="late_fee_amount" value="{{ old('late_fee_amount', $lateFeeAmount) }}" placeholder="5.00 or 10" class="{{ $input }}">
                </label>
            </div>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Suspension and termination</legend>
            <p class="text-ink-600">Counted from the due date of an unpaid invoice for the service.</p>
            <div class="flex flex-wrap gap-6">
                <label class="block">Suspend after
                    <input type="number" name="suspend_after_days" min="0" max="365" value="{{ old('suspend_after_days', $suspendDays) }}" placeholder="Off" class="{{ $input }}">
                    <span class="text-ink-500">days</span>
                </label>
                <label class="block">Terminate after
                    <input type="number" name="terminate_after_days" min="0" max="365" value="{{ old('terminate_after_days', $terminateDays) }}" placeholder="Off" class="{{ $input }}">
                    <span class="text-ink-500">days</span>
                </label>
            </div>
            <p class="text-ink-500">Leave either blank to turn it off.</p>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Exchange rates</legend>
            <input type="hidden" name="update_exchange_rates" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="update_exchange_rates" value="1" class="mt-1" @checked(old('update_exchange_rates', $updateRates))>
                <span>Update exchange rates from the European Central Bank every day at {{ App\Console\Scheduler::EXCHANGE_RATES_TIME }}. Prices don't change until you use Update product prices under <a href="{{ route('admin.currencies.index') }}" class="text-brand-600">Currencies</a>.</span>
            </label>
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save automation settings</button>
    </form>
</x-admin-layout>
