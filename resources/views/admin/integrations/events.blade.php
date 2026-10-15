{{-- Event checkboxes for webhooks and chat channels. Needs $events (key => label) and $selected (list of keys). --}}
<fieldset class="space-y-1">
    <legend class="mb-1 font-medium">Events</legend>
    <label class="flex items-center gap-2">
        <input type="checkbox" name="events[]" value="{{ App\Hooks\Hooks::ANY }}" @checked(in_array(App\Hooks\Hooks::ANY, $selected, true))>
        <span>Every event, including ones added later</span>
    </label>
    <div class="grid gap-1 sm:grid-cols-2">
        @foreach ($events as $key => $label)
            <label class="flex items-center gap-2">
                <input type="checkbox" name="events[]" value="{{ $key }}" @checked(in_array($key, $selected, true))>
                <span>{{ $label }} <code class="text-xs text-ink-500">{{ $key }}</code></span>
            </label>
        @endforeach
    </div>
</fieldset>
