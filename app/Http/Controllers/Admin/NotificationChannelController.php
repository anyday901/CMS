<?php

namespace App\Http\Controllers\Admin;

use App\Hooks\Hooks;
use App\Http\Controllers\Controller;
use App\Jobs\SendChatMessage;
use App\Models\Activity;
use App\Models\NotificationChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Chat notifications: post events to Slack, Discord or Microsoft Teams. */
class NotificationChannelController extends Controller
{
    public function __construct(private Hooks $hooks) {}

    public function index(): View
    {
        return view('admin.chat-channels.index', [
            'channels' => NotificationChannel::orderBy('name')->get(),
            'channel' => new NotificationChannel(['type' => 'slack', 'events' => ['ticket.opened', 'order.placed', 'staff.alert'], 'active' => true]),
            'events' => $this->hooks->events(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $channel = NotificationChannel::create($this->validated($request, true));
        Activity::record("Added {$channel->typeLabel()} channel {$channel->name}", $channel);

        return redirect()->route('admin.chat-channels.index')->with('status', 'Channel added. Send a test to check it works.');
    }

    public function edit(NotificationChannel $chatChannel): View
    {
        return view('admin.chat-channels.edit', ['channel' => $chatChannel, 'events' => $this->hooks->events()]);
    }

    public function update(Request $request, NotificationChannel $chatChannel): RedirectResponse
    {
        $data = $this->validated($request, false);
        // A blank URL keeps the saved one, which isn't shown again.
        if (blank($data['webhook_url'] ?? null)) {
            unset($data['webhook_url']);
        }
        $chatChannel->update($data);
        Activity::record("Updated {$chatChannel->typeLabel()} channel {$chatChannel->name}", $chatChannel);

        return redirect()->route('admin.chat-channels.index')->with('status', 'Channel saved.');
    }

    public function destroy(NotificationChannel $chatChannel): RedirectResponse
    {
        $chatChannel->delete();
        Activity::record("Deleted {$chatChannel->typeLabel()} channel {$chatChannel->name}");

        return redirect()->route('admin.chat-channels.index')->with('status', 'Channel deleted.');
    }

    /** Posts a test message straight away and says whether it went through. */
    public function test(NotificationChannel $chatChannel): RedirectResponse
    {
        $error = (new SendChatMessage($chatChannel->id, 'Test notification from '.config('app.name').'. Notifications will appear here.', route('admin.dashboard')))->post($chatChannel);
        $chatChannel->update($error === null ? ['last_sent_at' => now(), 'last_error' => null] : ['last_error' => $error]);

        return $error === null
            ? back()->with('status', "Test sent to {$chatChannel->name}.")
            : back()->withErrors(['test' => "The test didn't go through: {$error}"]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => [$creating ? 'required' : 'nullable', 'url:https', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in([Hooks::ANY, ...array_keys($this->hooks->events())])],
        ], ['events.required' => 'Pick at least one event.'], ['webhook_url' => 'incoming webhook URL']);

        $data['events'] = in_array(Hooks::ANY, $data['events'], true) ? [Hooks::ANY] : array_values(array_unique($data['events']));
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
