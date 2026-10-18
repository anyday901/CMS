{{-- Merges the unpaid invoices ticked in the table below it. --}}
<form id="merge-invoices" method="POST" action="{{ route('admin.invoices.merge') }}" class="mb-3 flex flex-wrap items-center gap-3 text-sm" onsubmit="return confirm('Merge the ticked invoices into one? The others are cancelled.')">
    @csrf
    <span class="text-ink-600">Tick two or more unpaid invoices of one client to merge them into one.</span>
    <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Merge selected</button>
</form>
@error('invoices')<p class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
