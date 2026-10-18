<label class="block">Credit limit <span class="text-ink-500">({{ $client->currency ?? config('billing.currency') }})</span>
    <input name="credit_limit" value="{{ old('credit_limit', $client->credit_limit !== null ? App\Support\Money::toInput($client->credit_limit) : '') }}" placeholder="Group's or default" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    <span class="mt-1 block text-ink-500">Unpaid invoices the client may run up before overdue suspension. Leave blank to use their group's limit or the default under Automation; 0 for none.</span>
</label>
