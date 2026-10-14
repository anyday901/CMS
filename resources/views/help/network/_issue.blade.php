<article class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
    <div class="mb-1 flex flex-wrap items-center gap-2">
        <h3 class="font-display text-base font-semibold">{{ $issue->title }}</h3>
        <span @class([
            'inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold',
            'bg-red-100 text-red-800' => in_array($issue->priority, ['critical', 'high']) && $issue->status !== 'resolved',
            'bg-sun-300/60 text-amber-900' => in_array($issue->priority, ['medium', 'low']) && $issue->status !== 'resolved',
            'bg-brand-100 text-brand-800' => $issue->status === 'resolved',
        ])>{{ $issue->statusLabel() }}</span>
        <span class="text-ink-500">{{ $issue->priorityLabel() }} priority</span>
    </div>
    <p class="mb-2 text-ink-600">
        Affects <strong class="text-ink-800">{{ $issue->affects() }}</strong> ·
        {{ $issue->starts_at->isFuture() ? 'Starts' : 'Started' }} {{ $issue->starts_at->format('M j, Y g:ia T') }}
        @if ($issue->ends_at) · {{ $issue->ends_at->isFuture() ? 'Expected to end' : 'Ended' }} {{ $issue->ends_at->format('M j, Y g:ia T') }}@endif
    </p>
    <div class="prose-content">{!! App\Support\Markdown::html($issue->description) !!}</div>
    <p class="mt-2 text-xs text-ink-500">Last updated {{ $issue->updated_at->diffForHumans() }}</p>
</article>
