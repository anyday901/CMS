{{-- One ticket message. $download is a closure giving the URL for an attachment. --}}
<article @class([
    'rounded-lg border p-4 text-sm',
    'border-amber-200 bg-amber-50' => $message->internal,
    'border-brand-200 bg-brand-50' => ! $message->internal && $message->fromStaff(),
    'border-ink-200 bg-white' => ! $message->internal && ! $message->fromStaff(),
])>
    <header class="mb-2 flex flex-wrap items-baseline gap-x-2 text-ink-500">
        <span class="font-semibold text-ink-900">{{ $message->authorName() }}</span>
        @if ($message->fromStaff())<span>Staff</span>@endif
        @if ($message->internal)<span class="font-semibold text-amber-800">Internal note</span>@endif
        @if ($message->via === 'email')<span>by email</span>@endif
        <time class="ml-auto">{{ $message->created_at->format('M j, Y g:ia') }}</time>
    </header>
    <div class="whitespace-pre-line break-words">{{ $message->body }}</div>
    @if ($message->attachments->isNotEmpty())
        <ul class="mt-3 flex flex-wrap gap-2">
            @foreach ($message->attachments as $file)
                <li><a href="{{ $download($file) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1 text-brand-700 ring-1 ring-ink-300 hover:bg-ink-50">📎 {{ $file->name }} <span class="text-ink-500">{{ $file->humanSize() }}</span></a></li>
            @endforeach
        </ul>
    @endif
</article>
