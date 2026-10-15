<label class="block">Name
    <input name="name" value="{{ old('name', $key->name) }}" required maxlength="255" placeholder="Accounting sync" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<fieldset>
    <legend class="mb-1">What it can do</legend>
    @foreach (App\Models\ApiKey::ABILITIES as $ability => $label)
        <label class="mb-1 flex items-start gap-2">
            <input type="checkbox" name="abilities[]" value="{{ $ability }}" class="mt-1" @checked(in_array($ability, old('abilities', $key->abilities ?? []), true))>
            <span>{{ $label }} <code class="text-xs text-ink-500">{{ $ability }}</code></span>
        </label>
    @endforeach
</fieldset>
<label class="block">Only from these IP addresses
    <textarea name="allowed_ips" rows="3" placeholder="203.0.113.10&#10;198.51.100.0/24" class="mt-1 w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">{{ old('allowed_ips', implode("\n", $key->allowed_ips ?? [])) }}</textarea>
    <span class="text-ink-500">One address or range per line. Leave it empty to allow any address.</span>
</label>
