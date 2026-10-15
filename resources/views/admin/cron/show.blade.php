@php
    $badge = fn (?string $status) => match ($status) {
        App\Models\ScheduledTaskRun::SUCCEEDED => 'bg-green-100 text-green-800',
        App\Models\ScheduledTaskRun::FAILED => 'bg-red-100 text-red-800',
        App\Models\ScheduledTaskRun::RUNNING => 'bg-amber-100 text-amber-800',
        default => 'bg-ink-100 text-ink-700',
    };
@endphp
<x-admin-layout title="Cron status">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Cron status</h1>
        <a href="{{ route('admin.automation.edit') }}" class="ml-auto text-sm text-brand-600">Automation settings</a>
        <a href="{{ route('admin.settings.edit') }}" class="text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">A cron entry on the server runs the scheduler every minute, and the scheduler starts each automatic task when it is due. Times are in the server's timezone ({{ config('app.timezone') }}).</p>

    @if ($stale)
        <div class="mb-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-semibold">{{ $lastSeen ? 'The scheduler hasn\'t run since '.$lastSeen->format('M j, Y g:i a').'.' : 'The scheduler hasn\'t run yet.' }}</p>
            <p class="mt-1">Without it there are no renewal invoices, reminders or suspensions. Add this line to the crontab of the user the site runs as (<code class="rounded bg-white px-1">crontab -e</code>):</p>
            <pre class="mt-2 overflow-x-auto rounded bg-white px-3 py-2 text-ink-800">{{ $cronLine }}</pre>
        </div>
    @else
        <div class="mb-6 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">The scheduler is running. A task last started {{ $lastSeen->diffForHumans() }} ({{ $lastSeen->format('g:i a') }}).</div>
    @endif

    <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500">
                <tr><th scope="col" class="px-4 py-2">Task</th><th scope="col" class="px-4 py-2">Runs</th><th scope="col" class="px-4 py-2">Last run</th><th scope="col" class="px-4 py-2">Last worked</th><th scope="col" class="px-4 py-2">Next run</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($tasks as $task)
                    <tr class="align-top">
                        <td class="px-4 py-2"><div class="font-medium">{{ $task['description'] }}</div><code class="text-xs text-ink-500">{{ $task['name'] }}</code></td>
                        <td class="whitespace-nowrap px-4 py-2"><code class="text-xs">{{ $task['expression'] }}</code></td>
                        <td class="px-4 py-2">
                            @if ($task['last'])
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge($task['last']->status) }}">{{ ucfirst($task['last']->status) }}</span>
                                <span class="whitespace-nowrap">{{ $task['last']->started_at->format('M j, g:i a') }}</span>
                                @if ($task['last']->seconds() !== null)<span class="text-ink-500">· {{ $task['last']->seconds() }}s</span>@endif
                                @if ($task['last']->error)<div class="mt-1 max-w-sm text-xs text-red-700">{{ $task['last']->error }}</div>@endif
                                @if ($task['failures'] > 1)<div class="mt-1 text-xs text-red-700">Failed {{ $task['failures'] }} times in the last day.</div>@endif
                            @else
                                <span class="text-ink-500">Not yet</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2">{{ $task['lastSuccess'] ? now()->parse($task['lastSuccess'])->format('M j, g:i a') : '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ $task['next']->format('M j, g:i a') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 font-display text-base font-bold">Queue worker</h2>
    <p class="mb-8 max-w-3xl text-sm text-ink-600">
        Emails and provisioning run on the queue ({{ $queue['driver'] }}).
        @if ($queue['pending'] !== null)
            {{ $queue['pending'] }} {{ Str::plural('job', $queue['pending']) }} waiting{{ $queue['oldest'] ? ', the oldest since '.$queue['oldest']->format('M j, g:i a') : '' }}.
            @if ($queue['oldest'] && $queue['oldest']->lt(now()->subMinutes(10)))<strong class="text-red-700">Jobs have waited over 10 minutes: check that the queue worker is running.</strong>@endif
        @endif
        @if ($queue['failed'] !== null)
            {{ $queue['failed'] }} failed {{ Str::plural('job', $queue['failed']) }}{{ $queue['failed'] ? ' (php artisan queue:failed lists them)' : '' }}.
        @endif
    </p>

    <h2 class="mb-2 font-display text-base font-bold">Recent runs</h2>
    @if ($recent->isEmpty())
        <p class="text-sm text-ink-500">No tasks have run yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th scope="col" class="px-4 py-2">Started</th><th scope="col" class="px-4 py-2">Task</th><th scope="col" class="px-4 py-2">Result</th><th scope="col" class="px-4 py-2">Took</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($recent as $run)
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-4 py-2">{{ $run->started_at->format('M j, g:i:s a') }}</td>
                            <td class="px-4 py-2"><code class="text-xs">{{ $run->task }}</code></td>
                            <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge($run->status) }}">{{ ucfirst($run->status) }}</span>@if ($run->error)<div class="mt-1 max-w-md text-xs text-red-700">{{ $run->error }}</div>@endif</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $run->seconds() !== null ? $run->seconds().'s' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin-layout>
