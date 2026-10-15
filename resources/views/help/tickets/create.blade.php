@php use App\Enums\TicketPriority; @endphp
<x-portal-layout title="Contact support" :store="true">
    @include('help._nav')
    <h1 class="mb-2 text-2xl font-semibold">Contact support</h1>
    <p class="mb-6 text-sm text-ink-600">Already a client? <a href="{{ route('portal.login') }}" class="text-brand-600">Log in</a> to open a ticket from your account.</p>
    @if ($departments->isEmpty())
        <p class="text-sm text-ink-500">Support tickets aren't set up yet. Please contact us by email.</p>
    @elseif ($department === null)
        <p class="mb-4 text-sm text-ink-600">Which team can help? You might also find an answer in the <a href="{{ route('kb.index') }}" class="text-brand-600">knowledge base</a>.</p>
        <div class="grid max-w-2xl gap-3 sm:grid-cols-2">
            @foreach ($departments as $item)
                <a href="{{ route('tickets.guest.create', ['department' => $item->id]) }}" class="block rounded-lg border border-ink-200 bg-white px-4 py-3 text-sm font-semibold hover:ring-1 hover:ring-brand-300">{{ $item->name }}</a>
            @endforeach
        </div>
    @else
        <form method="POST" action="{{ route('tickets.guest.store') }}" enctype="multipart/form-data" class="max-w-2xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            <input type="hidden" name="department_id" value="{{ $department->id }}">
            {{-- Left empty by people; bots fill it in. --}}
            <div class="hidden" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">Your name
                    <input name="name" value="{{ old('name') }}" required maxlength="250" autocomplete="name" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Email address
                    <input type="email" name="email" value="{{ old('email') }}" required maxlength="250" autocomplete="email" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <div>Department</div>
                    <div class="mt-1 py-2 font-medium">{{ $department->name }}@if ($departments->count() > 1) <a href="{{ route('tickets.guest.create') }}" class="ml-2 font-normal text-brand-600">Change</a>@endif</div>
                </div>
                <label class="block">Priority
                    <select name="priority" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach (TicketPriority::cases() as $priority)
                            <option value="{{ $priority->value }}" @selected(old('priority', TicketPriority::Medium->value) === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label class="block">Subject
                <input id="ticket-subject" name="subject" value="{{ old('subject') }}" required maxlength="250" autocomplete="off" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <div id="kb-suggestions" class="hidden rounded-lg border border-brand-200 bg-brand-50 p-4" aria-live="polite">
                <div class="font-semibold text-ink-900">These articles might answer your question</div>
                <ul class="mt-2 space-y-2"></ul>
            </div>
            @if ($fields->isNotEmpty())
                <div class="grid gap-4 sm:grid-cols-2">
                    @include('custom-fields._inputs', ['fields' => $fields, 'values' => [], 'wide' => 'sm:col-span-2'])
                </div>
            @endif
            <label class="block">Message
                <textarea id="ticket-body" name="body" rows="8" required class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('body') }}</textarea>
            </label>
            @include('tickets._attachments')
            <p class="text-ink-600">We'll email you a link to follow the ticket.</p>
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Send</button>
        </form>
        @include('tickets._kb-suggest', ['suggestUrl' => route('tickets.guest.suggest')])
    @endif
</x-portal-layout>
