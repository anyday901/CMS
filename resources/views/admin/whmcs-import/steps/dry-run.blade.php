@php $summary = $wizard->summary('dry_run'); @endphp

<p class="mb-4 text-ink-600">The dry run imports everything, compares totals with WHMCS (recurring revenue, balance due, payments and credit, per currency), then undoes it all. Nothing is saved and no emails are sent. It can take a few minutes on a large install, so keep this page open once it starts.</p>

@if ($summary)
    @include('admin.whmcs-import._report', ['run' => 'dry_run'])

    <p class="my-4 text-ink-600">Fix what you can in WHMCS (duplicate client emails, services on deleted products) and run it again until you are happy with the report.</p>
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.whmcs-import.show', ['step' => 4]) }}" class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Continue to the import</a>
        <form method="POST" action="{{ route('admin.whmcs-import.dry-run') }}">
            @csrf
            <button class="rounded-full px-4 py-2 font-medium text-brand-700 ring-1 ring-brand-500 hover:bg-brand-50">Run the dry run again</button>
        </form>
    </div>
@else
    <form method="POST" action="{{ route('admin.whmcs-import.dry-run') }}">
        @csrf
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Start the dry run</button>
    </form>
@endif
