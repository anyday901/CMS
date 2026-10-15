@php $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout title="Backups">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Database backups</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')
    <p class="mb-6 max-w-3xl text-sm text-ink-600">A backup is the whole database as a gzipped SQL file, kept in <code class="rounded bg-ink-100 px-1">{{ $path }}</code>. Uploaded files (ticket attachments, downloads and the logo) are in <code class="rounded bg-ink-100 px-1">storage/app</code>; back that folder up with your server's own backups. Backups hold everything about your clients, so keep downloaded copies somewhere safe.</p>

    @unless ($supported)
        <div class="mb-6 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">Backups from here work with MySQL, MariaDB and SQLite. This database uses {{ $driver }}; back it up with its own tools, such as pg_dump.</div>
    @endunless

    <div class="grid gap-6 md:grid-cols-3">
        <section class="rounded-lg border border-ink-200 bg-white text-sm md:col-span-2">
            <div class="flex flex-wrap items-center gap-3 border-b border-ink-200 px-6 py-4">
                <h2 class="text-base font-semibold">Backups</h2>
                @if ($supported)
                    <form method="POST" action="{{ route('admin.backups.store') }}" class="ml-auto">
                        @csrf
                        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Back up now</button>
                    </form>
                @endif
            </div>
            @if ($backups->isEmpty())
                <p class="px-6 py-4 text-ink-600">No backups yet.</p>
            @else
                <table class="w-full">
                    <thead class="text-left text-ink-500"><tr><th class="px-6 py-2 font-normal">File</th><th class="px-3 py-2 font-normal">Made</th><th class="px-3 py-2 text-right font-normal">Size</th><th class="px-6 py-2"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($backups as $backup)
                            <tr>
                                <td class="px-6 py-2 font-mono text-xs">{{ $backup['name'] }}</td>
                                <td class="px-3 py-2" title="{{ $backup['created_at']->toDayDateTimeString() }}">{{ $backup['created_at']->diffForHumans() }}</td>
                                <td class="px-3 py-2 text-right">{{ Illuminate\Support\Number::fileSize($backup['size'], 1) }}</td>
                                <td class="px-6 py-2 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.backups.download', $backup['name']) }}" class="text-brand-600">Download</a>
                                    <form method="POST" action="{{ route('admin.backups.destroy', $backup['name']) }}" class="ml-3 inline" onsubmit="return confirm('Delete this backup?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-700">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div class="border-t border-ink-200 px-6 py-4 text-ink-600">
                <p class="mb-1 font-medium text-ink-800">Restoring a backup</p>
                <p>On the server, run <code class="rounded bg-ink-100 px-1">php artisan backup:restore</code> and pick the backup. It puts the site in maintenance mode while it loads, and replaces everything in the database. A downloaded backup loads with <code class="rounded bg-ink-100 px-1">gunzip &lt; backup.sql.gz | mysql your_database</code> too.</p>
            </div>
        </section>

        <form method="POST" action="{{ route('admin.backups.settings') }}" class="space-y-3 self-start rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf @method('PUT')
            <h2 class="text-base font-semibold">Automatic backups</h2>
            <label class="block">Back up
                <select name="schedule" class="{{ $input }}">
                    @foreach ($schedules as $key => $label)<option value="{{ $key }}" @selected(old('schedule', $schedule) === $key)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="block">At
                <input type="time" name="time" value="{{ old('time', $time) }}" required class="{{ $input }} w-32">
                <span class="mt-1 block text-ink-500">Server time, {{ config('app.timezone') }}. Needs the cron entry; see <a href="{{ route('admin.cron.show') }}" class="text-brand-600">Cron status</a>.</span>
            </label>
            <label class="block">Keep the newest
                <input type="number" name="keep" min="1" max="365" value="{{ old('keep', $keep) }}" required class="{{ $input }} w-24">
                <span class="mt-1 block text-ink-500">Older backups are deleted after each new one.</span>
            </label>
            <label class="block">Also copy each backup to
                <select name="disk" class="{{ $input }}">
                    <option value="">Nowhere else</option>
                    @foreach ($disks as $name)<option value="{{ $name }}" @selected(old('disk', $disk) === $name)>{{ $name }} disk</option>@endforeach
                </select>
                <span class="mt-1 block text-ink-500">Disks are set up in <code class="rounded bg-ink-100 px-1">config/filesystems.php</code>, such as an S3 bucket with the AWS_ settings in .env. Copies go in its backups folder and aren't deleted here.</span>
            </label>
            <button class="rounded-full bg-white px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Save</button>
        </form>
    </div>
</x-admin-layout>
