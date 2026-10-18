@php $input = 'mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout title="Invoice numbering">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Invoice numbering</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">By default an invoice's number is its id, given when it's created, so unpaid and cancelled invoices take numbers too. Many countries want paid invoices numbered in order with no gaps; turn on sequential paid numbers for that, like WHMCS's "Sequential Paid Invoice Numbering".</p>

    <form method="POST" action="{{ route('admin.invoice-numbering.update') }}" class="max-w-2xl space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @method('PUT')

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Sequential paid numbers</legend>
            <input type="hidden" name="sequential_paid_numbers" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="sequential_paid_numbers" value="1" class="mt-1" @checked(old('sequential_paid_numbers', $enabled))>
                <span>Number invoices only once they're paid. Until then a new invoice is a proforma, shown as "Proforma #" and its id in the admin area, the portal, PDFs and emails. When it's paid it takes the next number below. Invoices made before this is turned on keep their numbers.</span>
            </label>
            <label class="block">Number format
                <input name="invoice_number_format" value="{{ old('invoice_number_format', $format) }}" required maxlength="40" class="{{ $input }} w-64">
                <span class="text-ink-500">{NUMBER} is the next number; {YEAR}, {MONTH} and {DAY} are the date of payment. It needs a letter or symbol too, such as <code class="rounded bg-ink-100 px-1">INV-{YEAR}-{NUMBER}</code>, so paid numbers can't be mistaken for proforma ones.</span>
            </label>
            @error('invoice_number_format')<p class="text-red-600">{{ $message }}</p>@enderror
            <input type="hidden" name="shown_next_number" value="{{ $next }}">
            <label class="block">Next number
                <input type="number" name="next_number" min="1" max="999999999" required value="{{ old('next_number', $next) }}" class="{{ $input }} w-32">
                <span class="text-ink-500">The next paid invoice is <strong>{{ $example }}</strong> (with today's date). Set it to carry on from your old system's numbers. A number already in use is passed over.</span>
            </label>
            @error('next_number')<p class="text-red-600">{{ $message }}</p>@enderror
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Credit notes</legend>
            <p class="text-ink-600">Credit notes are numbered in order as they're issued, from their own sequence, whether or not paid invoices are.</p>
            <label class="block">Number format
                <input name="credit_note_number_format" value="{{ old('credit_note_number_format', $creditNoteFormat) }}" required maxlength="40" class="{{ $input }} w-64">
                <span class="text-ink-500">The same placeholders, such as <code class="rounded bg-ink-100 px-1">CN-{NUMBER}</code>.</span>
            </label>
            @error('credit_note_number_format')<p class="text-red-600">{{ $message }}</p>@enderror
            <input type="hidden" name="shown_credit_note_next_number" value="{{ $creditNoteNext }}">
            <label class="block">Next number
                <input type="number" name="credit_note_next_number" min="1" max="999999999" required value="{{ old('credit_note_next_number', $creditNoteNext) }}" class="{{ $input }} w-32">
                <span class="text-ink-500">The next credit note is <strong>{{ $creditNoteExample }}</strong>.</span>
            </label>
            @error('credit_note_next_number')<p class="text-red-600">{{ $message }}</p>@enderror
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
