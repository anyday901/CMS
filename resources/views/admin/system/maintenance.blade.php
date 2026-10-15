<x-admin-layout title="Maintenance mode">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Maintenance mode</h1>
        <span class="rounded-full px-3 py-1 text-sm font-medium {{ $enabled ? 'bg-sun-300/40 text-amber-900' : 'bg-green-50 text-green-800' }}">{{ $enabled ? 'On' : 'Off' }}</span>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')

    <form method="POST" action="{{ route('admin.maintenance.update') }}" class="max-w-2xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        <p class="text-ink-600">While it's on, visitors to the portal, store and help pages see your message instead, with a 503 status so search engines come back later. The admin area keeps working, and so do the API, payment webhooks, ticket email and unsubscribe links. You can look around the portal while you're signed in here.</p>
        <input type="hidden" name="enabled" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="enabled" value="1" class="mt-1" @checked(old('enabled', $enabled))>
            <span>Turn maintenance mode on</span>
        </label>
        <label class="block">Message to visitors
            <textarea name="message" rows="3" maxlength="2000" placeholder="We're doing some maintenance. Please check back shortly." class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('message', $message) }}</textarea>
            <span class="text-ink-500">Leave blank for the standard message, which is translated for each visitor.</span>
        </label>
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
