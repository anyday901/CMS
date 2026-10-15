<x-admin-layout title="Settings">
    <h1 class="mb-6 text-2xl font-semibold">Settings</h1>
    <form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-2xl space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @method('PUT')

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Look and email</legend>
            <p class="text-ink-600">Your logo, colours, company details and the portal theme, and how email is sent. <a href="{{ route('admin.appearance.edit') }}" class="text-brand-600">Appearance</a> · <a href="{{ route('admin.mail.edit') }}" class="text-brand-600">Mail</a> · <a href="{{ route('admin.languages.index') }}" class="text-brand-600">Portal text and translations</a>.</p>
        </fieldset>

        @can('manage-system')
            <fieldset class="space-y-2">
                <legend class="mb-1 text-base font-semibold">System</legend>
                <p class="text-ink-600">Check the server is in order, take the portal offline for maintenance, back up the database, update CMS, and add modules and themes. <a href="{{ route('admin.system.health') }}" class="text-brand-600">System health</a> · <a href="{{ route('admin.maintenance.edit') }}" class="text-brand-600">Maintenance</a> · <a href="{{ route('admin.backups.index') }}" class="text-brand-600">Backups</a> · <a href="{{ route('admin.updates.index') }}" class="text-brand-600">Updates</a> · <a href="{{ route('admin.marketplace.index') }}" class="text-brand-600">Marketplace</a>.</p>
            </fieldset>
        @endcan

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Automation</legend>
            <p class="text-ink-600">When the nightly billing run happens, and the days it works by: renewal invoices, overdue reminders, late fees, suspension and termination. <a href="{{ route('admin.automation.edit') }}" class="text-brand-600">Automation settings</a> · <a href="{{ route('admin.cron.show') }}" class="text-brand-600">Cron status</a>.</p>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Integrations</legend>
            <p class="text-ink-600">Optional modules, and telling other systems and people when things happen. <a href="{{ route('admin.addons.index') }}" class="text-brand-600">Add-ons</a> · <a href="{{ route('admin.webhooks.index') }}" class="text-brand-600">Webhooks</a> · <a href="{{ route('admin.chat-channels.index') }}" class="text-brand-600">Chat notifications</a> · <a href="{{ route('admin.sms.edit') }}" class="text-brand-600">SMS</a> · <a href="{{ route('admin.api-keys.index') }}" class="text-brand-600">API keys</a> · <a href="{{ route('admin.affiliates.settings') }}" class="text-brand-600">Affiliates</a>.</p>
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
            <p class="text-ink-600">Add extra fields to clients and services under <a href="{{ route('admin.custom-fields.index') }}" class="text-brand-600">Custom fields</a>, and sort clients into <a href="{{ route('admin.client-groups.index') }}" class="text-brand-600">Client groups</a>.</p>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Support</legend>
            <p class="text-ink-600">Add and edit ticket departments, their email addresses and response time targets (SLAs) under <a href="{{ route('admin.departments.index') }}" class="text-brand-600">Departments</a>, and move, reassign or flag tickets automatically with <a href="{{ route('admin.escalation-rules.index') }}" class="text-brand-600">Escalation rules</a>.</p>
            <label class="block">Close answered tickets after this many hours without a reply
                <input type="number" name="close_inactive_hours" min="1" max="8760" value="{{ old('close_inactive_hours', $closeInactiveHours) }}" placeholder="Off" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">The client is emailed that the ticket was closed and can reply to open it again. Leave blank to keep answered tickets open.</span>
            </label>
            <label class="flex items-start gap-2"><input type="checkbox" name="guest_tickets" value="1" class="mt-1" @checked(old('guest_tickets', $guestTickets))>
                <span>Accept tickets from people without an account, by email to a department and with the <a href="{{ route('tickets.guest.create') }}" class="text-brand-600">contact form</a>. They're emailed a private link to follow the ticket, so keep the "Ticket received" email template on.</span>
            </label>
        </fieldset>

        <fieldset id="spam" class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Ticket spam filter</legend>
            <p class="text-ink-600">Checks piped ticket email and the contact form. What it catches is listed in the <a href="{{ route('admin.tickets.inbound', ['outcome' => 'spam']) }}" class="text-brand-600">email and spam log</a>; nothing caught becomes a ticket.</p>
            <label class="flex items-start gap-2"><input type="checkbox" name="spam_use_headers" value="1" class="mt-1" @checked(old('spam_use_headers', $spam['use_spam_headers']))>
                <span>Drop email your mail server's spam checker (SpamAssassin or Rspamd) marked as spam</span>
            </label>
            <label class="block">Blocked senders
                <textarea name="spam_blocked_senders" rows="3" placeholder="spammer@example.com&#10;@spam-domain.example" class="mt-1 block w-full max-w-md rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">{{ old('spam_blocked_senders', implode("\n", $spam['blocked_senders'])) }}</textarea>
                <span class="text-ink-500">One per line: an address, or @ and a domain to block the domain and its subdomains.</span>
            </label>
            <div class="grid max-w-3xl gap-3 sm:grid-cols-2">
                <label class="block">Blocked words in the subject
                    <textarea name="spam_blocked_subjects" rows="3" placeholder="casino bonus" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('spam_blocked_subjects', implode("\n", $spam['blocked_subjects'])) }}</textarea>
                </label>
                <label class="block">Blocked words in the message
                    <textarea name="spam_blocked_phrases" rows="3" placeholder="cheap backlinks" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('spam_blocked_phrases', implode("\n", $spam['blocked_phrases'])) }}</textarea>
                </label>
            </div>
            <p class="text-ink-500">One word or phrase per line; capitals don't matter.</p>
            <label class="block">New tickets one sender can open in an hour
                <input type="number" name="spam_new_tickets_per_hour" min="1" max="1000" value="{{ old('spam_new_tickets_per_hour', $spam['new_tickets_per_hour']) }}" placeholder="No limit" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Stops a mail loop or a flood from one address. Replies to existing tickets aren't limited.</span>
            </label>
        </fieldset>

        <fieldset id="languages" class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Languages</legend>
            <p class="text-ink-600">The languages the portal, client emails, knowledge base articles and announcements come in. Visitors get the language their browser asks for and can pick another; signed-in clients keep the one they picked, and their emails use it too. Change any portal wording, or translate it into a language CMS doesn't ship, under <a href="{{ route('admin.languages.index') }}" class="text-brand-600">Portal text</a>, and translate each email under <a href="{{ route('admin.email-templates.index') }}" class="text-brand-600">Email templates</a>.</p>
            <div class="grid max-w-3xl gap-3 sm:grid-cols-2">
                <label class="block">Main language
                    <input name="main_language" value="{{ old('main_language', $mainLanguage) }}" required maxlength="12" list="language-codes" class="mt-1 block w-28 rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">
                    <span class="text-ink-500">The language articles and emails are written in.</span>
                </label>
                <label class="block">Other languages
                    <input name="languages" value="{{ old('languages', implode(', ', $languages)) }}" placeholder="fr, de, pt-BR" class="mt-1 block w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">
                    <span class="text-ink-500">Language codes, separated by commas.</span>
                </label>
            </div>
            <datalist id="language-codes">
                @foreach (App\Support\Language::NAMES as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
            </datalist>
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
            <legend class="mb-1 text-base font-semibold">Security</legend>
            <p class="text-ink-600">Failed login lockouts, IP bans, staff two-factor, CAPTCHA on public forms and the login history: <a href="{{ route('admin.security.show') }}" class="text-brand-600">Security settings</a>.</p>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Moving from WHMCS</legend>
            <p class="text-ink-600">Bring clients, services, invoices and tickets over from WHMCS, step by step, with a dry run first: <a href="{{ route('admin.whmcs-import.show') }}" class="text-brand-600">Import from WHMCS</a>.</p>
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save settings</button>
    </form>
</x-admin-layout>
