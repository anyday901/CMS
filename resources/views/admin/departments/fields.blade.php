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
