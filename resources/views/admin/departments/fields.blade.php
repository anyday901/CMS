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
            <label class="block">{{ $priority->label() }}
                <input type="number" step="0.25" min="0.1" max="8760" name="sla_hours[{{ $priority->value }}]" value="{{ old("sla_hours.{$priority->value}", $department->sla_hours[$priority->value] ?? '') }}" placeholder="Hours" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        @endforeach
    </div>
</fieldset>
