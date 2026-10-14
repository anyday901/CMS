@php use App\Enums\TicketStatus; @endphp
<x-portal-layout :title="'Ticket #'.$ticket->id">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">#{{ $ticket->id }} {{ $ticket->subject }}</h1>
        <x-status-badge :status="$ticket->status" />
        @if ($ticket->status !== TicketStatus::Closed)
            <form method="POST" action="{{ route('portal.tickets.close', $ticket) }}" class="ml-auto">@csrf
                <button class="rounded-full px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Close ticket</button>
            </form>
        @endif
    </div>
    <p class="mb-6 text-sm text-ink-500">{{ $ticket->department->name }} · opened {{ $ticket->created_at->format('M j, Y') }}</p>
    @if ($fields->isNotEmpty())
        <div class="mb-6 grid max-w-3xl gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-2">
            @include('custom-fields._values', ['fields' => $fields, 'values' => $ticket->custom_fields ?? [], 'wide' => 'sm:col-span-2'])
        </div>
    @endif

    <div class="max-w-3xl space-y-4">
        @foreach ($ticket->messages as $message)
            @include('tickets._message', ['download' => fn ($file) => route('portal.tickets.attachment', [$ticket, $file])])
        @endforeach

        @if ($ticket->feedback)
            <div id="feedback" class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <div class="font-semibold">Your feedback</div>
                <div class="mt-1"><x-rating :value="$ticket->feedback->rating" /> <span class="text-ink-600">{{ $ticket->feedback->label() }}</span></div>
                @if ($ticket->feedback->comment)<p class="mt-1 whitespace-pre-line text-ink-700">{{ $ticket->feedback->comment }}</p>@endif
            </div>
        @elseif ($ticket->wantsFeedback())
            <form id="feedback" method="POST" action="{{ route('portal.tickets.feedback', $ticket) }}" class="space-y-3 rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm">
                @csrf
                <fieldset>
                    <legend class="font-display text-base font-semibold">How did we do?</legend>
                    <p class="text-ink-600">Rate the support you had on this ticket.</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (App\Models\TicketFeedback::LABELS as $rating => $label)
                            <label for="rating-{{ $rating }}" class="flex cursor-pointer items-center gap-1.5 rounded-full bg-white px-3 py-1.5 ring-1 ring-ink-300 has-[:checked]:bg-brand-600 has-[:checked]:text-white has-[:checked]:ring-brand-600">
                                <input id="rating-{{ $rating }}" type="radio" name="rating" value="{{ $rating }}" required class="sr-only" @checked((int) old('rating') === $rating)>
                                <span aria-hidden="true">{{ str_repeat('★', $rating) }}</span> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('rating')<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
                </fieldset>
                <label class="block">Anything to add? <span class="text-ink-500">(optional)</span>
                    <textarea name="comment" rows="3" maxlength="2000" class="mt-1 block w-full rounded-md bg-white px-3 py-2 ring-1 ring-ink-300">{{ old('comment') }}</textarea>
                </label>
                <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Send feedback</button>
            </form>
        @endif

        <form method="POST" action="{{ route('portal.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <label for="reply-body" class="block font-display text-base font-semibold">{{ $ticket->status === TicketStatus::Closed ? 'Reply to reopen this ticket' : 'Reply' }}</label>
            <textarea id="reply-body" name="body" rows="5" required class="block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('body') }}</textarea>
            @include('tickets._attachments')
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Send reply</button>
        </form>
    </div>
</x-portal-layout>
