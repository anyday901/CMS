{{-- $domain (new or saved), $registrars --}}
<div class="grid gap-4 sm:grid-cols-3">
    <label class="block">Status
        <select name="status" class="mt-1 w-full rounded-md bg-white px-3 py-2 ring-1 ring-ink-300">
            @foreach (App\Enums\DomainStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected(old('status', $domain->status?->value ?? 'active') === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Registrar
        <select name="registrar" class="mt-1 w-full rounded-md bg-white px-3 py-2 ring-1 ring-ink-300">
            <option value="">{{ $domain->exists ? 'None' : 'The TLD\'s registrar' }}</option>
            @foreach ($registrars as $key => $registrar)
                <option value="{{ $key }}" @selected(old('registrar', $domain->registrar) === $key)>{{ $registrar->label() }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">Renewal term (years)
        <input type="number" name="years" min="1" max="10" required value="{{ old('years', $domain->years ?? 1) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Registered
        <input type="date" name="registration_date" value="{{ old('registration_date', $domain->registration_date?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Expires
        <input type="date" name="expiry_date" value="{{ old('expiry_date', $domain->expiry_date?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    @if ($domain->exists)
        <label class="block">Next renewal due
            <input type="date" name="next_due_date" value="{{ old('next_due_date', $domain->next_due_date?->toDateString()) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
    @endif
    <label class="block">Renewal price
        <input name="recurring_amount" value="{{ old('recurring_amount', $domain->exists ? App\Support\Money::toInput($domain->recurring_amount) : '') }}" @if ($domain->exists) required @endif placeholder="{{ $domain->exists ? '' : 'From the TLD\'s prices' }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
<input type="hidden" name="auto_renew" value="0">
<label class="flex items-center gap-2"><input type="checkbox" name="auto_renew" value="1" @checked(old('auto_renew', $domain->auto_renew ?? true))> Renew automatically: invoice the renewal before it expires</label>
