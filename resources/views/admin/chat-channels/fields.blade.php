@php $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<label class="block">Service
    <select name="type" class="{{ $input }}">
        @foreach (App\Models\NotificationChannel::TYPES as $type => $label)
            <option value="{{ $type }}" @selected(old('type', $channel->type) === $type)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block">Name
    <input name="name" value="{{ old('name', $channel->name) }}" required maxlength="255" placeholder="#billing" class="{{ $input }}">
</label>
<label class="block">Incoming webhook URL
    <input type="url" name="webhook_url" @if (! $channel->exists) required @endif maxlength="2000" placeholder="{{ $channel->exists ? 'Saved; leave blank to keep it' : 'https://hooks.slack.com/services/…' }}" class="{{ $input }}" autocomplete="off">
    <span class="mt-1 block text-ink-500">Slack: an incoming webhook from a Slack app. Discord: Server settings › Integrations › Webhooks. Teams: the Workflows app's "Post to a channel when a webhook request is received".</span>
</label>
@include('admin.integrations.events', ['selected' => old('events', $channel->events ?? [])])
<input type="hidden" name="active" value="0">
<label class="flex items-center gap-2">
    <input type="checkbox" name="active" value="1" @checked(old('active', $channel->active))>
    <span>Active</span>
</label>
