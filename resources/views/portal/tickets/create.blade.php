@php use App\Enums\TicketPriority; @endphp
<x-portal-layout title="Open a ticket">
    <h1 class="mb-6 text-2xl font-semibold">Open a ticket</h1>
    @if ($departments->isEmpty())
        <p class="text-sm text-ink-500">Support tickets aren't set up yet. Please contact us by email.</p>
    @else
        <form method="POST" action="{{ route('portal.tickets.store') }}" enctype="multipart/form-data" class="max-w-2xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">Department
                    <select name="department_id" required class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((int) old('department_id') === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Priority
                    <select name="priority" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach (TicketPriority::cases() as $priority)
                            <option value="{{ $priority->value }}" @selected(old('priority', TicketPriority::Medium->value) === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label class="block">Subject
                <input name="subject" value="{{ old('subject') }}" required maxlength="250" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Message
                <textarea name="body" rows="8" required class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('body') }}</textarea>
            </label>
            @include('tickets._attachments')
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Open ticket</button>
        </form>
    @endif
</x-portal-layout>
