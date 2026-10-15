<label class="block">Name
    <input name="name" value="{{ old('name', $department->name) }}" required placeholder="Technical support" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Email address (optional)
    <input type="email" name="email" value="{{ old('email', $department->email) }}" placeholder="support@example.com" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    <span class="text-ink-500">Ticket emails reply to this address, and clients can email it to open a ticket once it's piped to the app.</span>
</label>
<label class="flex items-center gap-2">
    <input type="checkbox" name="active" value="1" @checked(old('active', $department->active))>
    <span>Clients can open tickets in this department</span>
</label>
<fieldset class="space-y-2 border-t border-ink-100 pt-3">
    <legend class="font-semibold">Response time targets (SLA)</legend>
    <p class="text-ink-500">Hours staff have to answer a ticket waiting on them, by priority. Leave empty for no target. Tickets past it show as overdue, and escalation rules can act on them.</p>
    <div class="grid grid-cols-2 gap-3">
        @foreach (App\Enums\TicketPriority::cases() as $priority)
            <label for="sla-{{ $priority->value }}" class="block">{{ $priority->label() }}
                <input id="sla-{{ $priority->value }}" type="number" step="0.25" min="0.1" max="8760" name="sla_hours[{{ $priority->value }}]" value="{{ old("sla_hours.{$priority->value}", $department->sla_hours[$priority->value] ?? '') }}" placeholder="Hours" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        @endforeach
    </div>
    <label class="flex items-start gap-2">
        <input type="checkbox" name="sla_business_hours" value="1" class="mt-1" @checked(old('sla_business_hours', $department->sla_business_hours))>
        <span>Count business hours only, so a ticket that comes in at night or on a holiday is due once you're open. Set your opening hours under <a href="{{ route('admin.settings.edit') }}#business-hours" class="text-brand-600">Settings</a>.</span>
    </label>
</fieldset>
<label class="flex items-start gap-2 border-t border-ink-100 pt-3">
    <input type="checkbox" name="feedback_requests" value="1" class="mt-1" @checked(old('feedback_requests', $department->feedback_requests))>
    <span>Ask clients to rate the support when a ticket is closed. Ratings show on the ticket and under <a href="{{ route('admin.tickets.feedback') }}" class="text-brand-600">Ticket feedback</a>.</span>
</label>
