<x-admin-layout title="Logs">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Logs</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')
    <p class="mb-6 max-w-3xl text-sm text-ink-600">The app's own log files, in <code class="rounded bg-ink-100 px-1">{{ $path }}</code>: errors, failed jobs and anything else Laravel writes down. They can hold client details and server paths, so only download them for yourself or for someone you trust with the server.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <section class="rounded-lg border border-ink-200 bg-white text-sm md:col-span-2">
            <h2 class="border-b border-ink-200 px-6 py-4 text-base font-semibold">Log files</h2>
            @if ($files->isEmpty())
                <p class="px-6 py-4 text-ink-600">No log files yet. Laravel makes one when it first has something to write down.</p>
            @else
                <table class="w-full">
                    <thead class="text-left text-ink-500"><tr><th class="px-6 py-2 font-normal">File</th><th class="px-3 py-2 font-normal">Last written</th><th class="px-3 py-2 text-right font-normal">Size</th><th class="px-6 py-2"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($files as $file)
                            <tr>
                                <td class="px-6 py-2 font-mono text-xs"><a href="{{ route('admin.system.logs.show', $file['name']) }}" class="text-brand-600">{{ $file['name'] }}</a></td>
                                <td class="px-3 py-2" title="{{ $file['modified_at']->toDayDateTimeString() }}">{{ $file['modified_at']->diffForHumans() }}</td>
                                <td class="px-3 py-2 text-right">{{ Illuminate\Support\Number::fileSize($file['size'], 1) }}</td>
                                <td class="px-6 py-2 text-right whitespace-nowrap"><a href="{{ route('admin.system.logs.download', $file['name']) }}" class="text-brand-600" aria-label="Download {{ $file['name'] }}">Download</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <aside class="space-y-4 self-start text-sm">
            <div class="rounded-lg border border-ink-200 bg-white p-6">
                <h2 class="mb-2 text-base font-semibold">Other logs</h2>
                @can('manage-settings')
                    <ul class="space-y-2">
                        <li><a href="{{ route('admin.activity.index') }}" class="text-brand-600">Activity log</a><span class="block text-ink-500">What staff, clients and the automation did.</span></li>
                        <li><a href="{{ route('admin.emails.index') }}" class="text-brand-600">Email log</a><span class="block text-ink-500">Every email sent, and any error.</span></li>
                        <li><a href="{{ route('admin.gateway-log.index') }}" class="text-brand-600">Gateway log</a><span class="block text-ink-500">Webhooks and callbacks from the payment gateways.</span></li>
                        <li><a href="{{ route('admin.cron.show') }}" class="text-brand-600">Cron status</a><span class="block text-ink-500">When each automatic task last ran.</span></li>
                    </ul>
                @else
                    <p class="text-ink-600">The activity, email and gateway logs are for staff who manage settings.</p>
                @endcan
            </div>
            <div class="rounded-lg border border-ink-200 bg-white p-6">
                <h2 class="mb-2 text-base font-semibold">How much is kept</h2>
                <p class="text-ink-600">Set <code class="rounded bg-ink-100 px-1">LOG_STACK=daily</code> in .env for one file a day, kept for <code class="rounded bg-ink-100 px-1">LOG_DAILY_DAYS</code> days (14 by default). Only the last {{ Illuminate\Support\Number::fileSize(config('logging.viewer.max_bytes'), 0) }} of a file is shown here; download it for the rest.</p>
            </div>
        </aside>
    </div>
</x-admin-layout>
