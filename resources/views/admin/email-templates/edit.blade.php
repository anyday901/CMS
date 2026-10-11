<x-admin-layout :title="$definition['name'].' email'">
    <h1 class="mb-2 text-2xl font-semibold">{{ $definition['name'] }} email</h1>
    <p class="mb-4 max-w-3xl text-sm text-ink-600">{{ $definition['description'] }}</p>
    <form method="POST" action="{{ route('admin.email-templates.update', $key) }}" class="max-w-3xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        <input type="hidden" name="enabled" value="0">
        <label class="flex items-center gap-2">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $template?->enabled ?? true))> Send this email
        </label>
        <label class="block">Subject
            <input name="subject" value="{{ old('subject', $template?->subject ?? $definition['subject']) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <label class="block">Body
            <textarea name="body" rows="14" required class="mt-1 w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">{{ old('body', $template?->body ?? $definition['body']) }}</textarea>
            <span class="text-ink-500">Markdown: blank lines between paragraphs, <code>**bold**</code>, <code>[link text](url)</code>.</span>
        </label>
        <div>
            <div class="mb-1 font-medium">Placeholders</div>
            <p class="text-ink-600">
                @foreach (array_merge(App\Mail\EmailTemplates::COMMON, $definition['placeholders']) as $placeholder)
                    <code class="mr-1 rounded bg-ink-100 px-1.5 py-0.5">{{ '{'.$placeholder.'}' }}</code>
                @endforeach
            </p>
            @if ($definition['replyable'] ?? false)
                <p class="mt-2 text-ink-600">Clients can reply to this email to add to the ticket. A "{{ App\Mail\EmailTemplates::REPLY_MARKER }}" line is added at the top automatically.</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save template</button>
            @if ($template)
                <button name="reset" value="1" formnovalidate class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100" onclick="return confirm('Go back to the default wording?')">Reset to default</button>
            @endif
        </div>
    </form>
</x-admin-layout>
