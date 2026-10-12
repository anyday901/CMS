@php $summary = $wizard->summary('dry_run'); @endphp

<p class="mb-4 text-ink-600">This runs the same import as the dry run, and this time keeps it. Clients, services, invoices and tickets keep their WHMCS numbers. WHMCS itself is not changed, so it can keep running side by side until you switch over.</p>

<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
    @foreach (array_intersect_key($summary['counts'], array_flip(['Clients', 'Services', 'Invoices', 'Tickets'])) as $section => $count)
        <div class="rounded-md border border-ink-200 p-3">
            <div class="text-ink-500">{{ $section }}</div>
            <div class="font-display text-lg font-bold">{{ number_format($count['imported']) }}</div>
        </div>
    @endforeach
</div>

@if ($summary['mismatches'] > 0)
    <p class="mb-4 rounded-md bg-amber-50 px-4 py-3 text-amber-900">The last dry run had {{ $summary['mismatches'] }} {{ Str::plural('total', $summary['mismatches']) }} that differ from WHMCS. <a href="{{ route('admin.whmcs-import.show', ['step' => 3]) }}" class="underline">Look at the dry run again</a> before importing.</p>
@endif

<form method="POST" action="{{ route('admin.whmcs-import.import') }}" class="space-y-4">
    @csrf
    <label class="flex items-start gap-2">
        <input type="checkbox" name="confirm" value="1" class="mt-1">
        <span>I have checked the dry run report and want to import WHMCS into this install now.</span>
    </label>
    <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Import now</button>
    <p class="text-ink-500">Keep this page open until it finishes. If anything fails part way, nothing is kept and you can try again.</p>
</form>
