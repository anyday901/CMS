<x-admin-layout title="Settings">
    <h1 class="mb-6 text-2xl font-semibold">Settings</h1>
    <form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-2xl space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @method('PUT')

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Late fees</legend>
            <p class="text-ink-600">The nightly billing run adds one late fee to an invoice that is still unpaid this many days after its due date. Late fees are not taxed.</p>
            <label class="block">Days after the due date
                <input type="number" name="late_fee_after_days" min="0" max="365" value="{{ old('late_fee_after_days', $lateFeeDays) }}" placeholder="Off" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300">
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
                    <input name="late_fee_amount" value="{{ old('late_fee_amount', $lateFeeAmount) }}" placeholder="5.00 or 10" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300">
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

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Invoice emails</legend>
            <input type="hidden" name="attach_invoice_pdf" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="attach_invoice_pdf" value="1" class="mt-1" @checked(old('attach_invoice_pdf', $attachInvoicePdf))>
                <span>Attach the invoice PDF to new invoice emails.</span>
            </label>
            <p class="text-ink-600">Change the wording of client emails under <a href="{{ route('admin.email-templates.index') }}" class="text-brand-600">Email templates</a>.</p>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Orders</legend>
            <input type="hidden" name="hold_new_orders" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="hold_new_orders" value="1" class="mt-1" @checked(old('hold_new_orders', $holdNewOrders))>
                <span>Hold new orders for review. Services from the store stay pending, even once paid, until staff accept the order under <a href="{{ route('admin.orders.index') }}" class="text-brand-600">Orders</a>.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Clients and services</legend>
            <p class="text-ink-600">Add extra fields to clients and services under <a href="{{ route('admin.custom-fields.index') }}" class="text-brand-600">Custom fields</a>.</p>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Support</legend>
            <p class="text-ink-600">Add and edit ticket departments, their email addresses and response time targets (SLAs) under <a href="{{ route('admin.departments.index') }}" class="text-brand-600">Departments</a>, and move, reassign or flag tickets automatically with <a href="{{ route('admin.escalation-rules.index') }}" class="text-brand-600">Escalation rules</a>.</p>
            <label class="block">Close answered tickets after this many hours without a reply
                <input type="number" name="close_inactive_hours" min="1" max="8760" value="{{ old('close_inactive_hours', $closeInactiveHours) }}" placeholder="Off" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">The client is emailed that the ticket was closed and can reply to open it again. Leave blank to keep answered tickets open.</span>
            </label>
        </fieldset>

        <fieldset id="business-hours" class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Business hours</legend>
            <p class="text-ink-600">Departments set to count business hours only measure their response time targets in these hours. Other departments count every hour.</p>
            <table class="text-sm">
                <thead class="text-left text-ink-500"><tr><th class="pr-4 font-normal">Day</th><th class="pr-4 font-normal">Opens</th><th class="font-normal">Closes</th></tr></thead>
                <tbody>
                    @foreach (App\Tickets\BusinessHours::DAYS as $day => $name)
                        @php $hours = $businessHours->week[$day] ?? null; @endphp
                        <tr>
                            <td class="py-1 pr-4">
                                <input type="hidden" name="hours[{{ $day }}][open]" value="0">
                                <label for="hours-{{ $day }}" class="flex items-center gap-2"><input id="hours-{{ $day }}" type="checkbox" name="hours[{{ $day }}][open]" value="1" @checked(old("hours.{$day}.open", $hours !== null))> {{ $name }}</label>
                            </td>
                            <td class="py-1 pr-4"><input type="time" name="hours[{{ $day }}][from]" value="{{ old("hours.{$day}.from", $hours[0] ?? '09:00') }}" aria-label="{{ $name }} opens" class="rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                            <td class="py-1"><input type="time" name="hours[{{ $day }}][to]" value="{{ old("hours.{$day}.to", $hours[1] ?? '17:00') }}" aria-label="{{ $name }} closes" class="rounded-md px-2 py-1 ring-1 ring-ink-300">
                                @error("hours.{$day}.to")<div class="text-red-700">{{ $message }}</div>@enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <label class="block">Closed on these dates
                <textarea name="holidays" rows="3" placeholder="2026-12-25" class="mt-1 block w-full max-w-xs rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">{{ old('holidays', implode("\n", $businessHours->holidays)) }}</textarea>
                <span class="text-ink-500">One date per line, as YYYY-MM-DD, such as public holidays.</span>
            </label>
            <label class="block">Timezone
                <select name="timezone" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}" @selected(old('timezone', $businessHours->timezone) === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </label>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Activity log</legend>
            <label class="block">Keep entries for this many days
                <input type="number" name="activity_retention_days" min="1" max="36500" value="{{ old('activity_retention_days', $activityRetentionDays) }}" placeholder="Forever" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Older entries are deleted every night. Leave blank to keep them forever.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Moving from WHMCS</legend>
            <p class="text-ink-600">Bring clients, services, invoices and tickets over from WHMCS, step by step, with a dry run first: <a href="{{ route('admin.whmcs-import.show') }}" class="text-brand-600">Import from WHMCS</a>.</p>
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save settings</button>
    </form>
</x-admin-layout>
