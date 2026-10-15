<p class="mb-4 text-ink-600">Check these before connecting. Reading WHMCS never changes it, so you can run through the walkthrough while WHMCS keeps working as usual.</p>

<ul class="mb-6 space-y-4">
    <li class="flex gap-3">
        @if ($blockers === [])
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white" aria-hidden="true">&#10003;</span>
            <div><strong>This install is empty.</strong> <span class="text-ink-600">WHMCS client, service, invoice and ticket numbers are kept, so the import needs an install with no clients, products, services, invoices or tickets yet.</span></div>
        @else
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-600 text-white" aria-hidden="true">!</span>
            <div><strong>This install already has {{ implode(', ', $blockers) }}.</strong> <span class="text-ink-600">WHMCS numbers are kept, so the import needs a new, empty install. If it only holds test data, run <code class="rounded bg-ink-100 px-1">php artisan migrate:fresh</code> on the server, create your admin account again, then come back here.</span></div>
        @endif
    </li>
    <li class="flex gap-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ink-100 font-semibold text-ink-700" aria-hidden="true">1</span>
        <div><strong>Get a copy of the WHMCS database.</strong> <span class="text-ink-600">Either a MySQL user with read-only (SELECT) access to the live WHMCS database, or a dump restored into a new database this server can reach.</span></div>
    </li>
    <li class="flex gap-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ink-100 font-semibold text-ink-700" aria-hidden="true">2</span>
        <div><strong>Optional: copy the WHMCS attachments and downloads folders to this server</strong> <span class="text-ink-600">to bring ticket attachments and the files on your Downloads page over.</span></div>
    </li>
    <li class="flex gap-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ink-100 font-semibold text-ink-700" aria-hidden="true">3</span>
        <div><strong>Optional: find WHMCS's encryption hash</strong> <span class="text-ink-600">(the <code class="rounded bg-ink-100 px-1">$cc_encryption_hash</code> line in its configuration.php) to bring server passwords and your clients' control panel logins over.</span></div>
    </li>
    <li class="flex gap-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ink-100 font-semibold text-ink-700" aria-hidden="true">4</span>
        <div><strong>Keep client emails switched off here</strong> <span class="text-ink-600">(<code class="rounded bg-ink-100 px-1">MAIL_MAILER=log</code>) until you switch over, so clients don't hear from both systems.</span></div>
    </li>
</ul>

<p class="mb-6 rounded-md bg-ink-50 px-4 py-3 text-ink-600">Not copied: disabled admins, WHMCS email templates, and add-on and domain renewals. The dry run report lists everything it skips. With tens of thousands of invoices, the command line is more reliable than a browser: <code class="rounded bg-white px-1">php artisan whmcs:import --dry-run</code> runs the same import (add <code class="rounded bg-white px-1">--hash=</code> with the encryption hash).</p>

@if ($blockers === [])
    <a href="{{ route('admin.whmcs-import.show', ['step' => 2]) }}" class="inline-block rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Continue</a>
@endif
