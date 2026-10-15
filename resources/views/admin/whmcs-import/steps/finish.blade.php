@php
    $summary = $wizard->summary('import');
    $imported = $wizard->imported();
@endphp

<p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-green-800">WHMCS was imported on {{ \Illuminate\Support\Carbon::parse($imported['imported_at'])->format('M j, Y \a\t g:i a') }}.</p>

@if ($summary)
    @include('admin.whmcs-import._report', ['run' => 'import'])
@endif

<h3 class="mb-3 mt-6 text-base font-semibold">What to do next</h3>
<ol class="space-y-3">
    @foreach ([
        ['Check your staff.', 'WHMCS admins came over as staff accounts with their WHMCS passwords, in the closest built-in role. Check each one\'s role under Staff, and set a password for any the report says need one.'],
        ['Check your servers.', 'Open each server under Products > Servers and press Test connection. Products on cPanel, Plesk and DirectAdmin kept their package; Proxmox products need their template, cores and memory set.'],
        ['Check your support setup.', 'Escalation rules came over turned off: check each one under Tickets > Escalation rules and turn it on. Knowledge base articles that were for logged-in clients only came over unpublished. Set business hours and response time targets on each department. Tickets from people without an account came over as guest tickets; turn on guest tickets under Settings to accept new ones too.'],
        ['Let clients log in as before.', 'They use their client email address and their WHMCS password, which is stored again with this app\'s hashing the first time it works.'],
        ['Check your reports.', 'Reports cover the years before the switch, from the history that came over. Custom WHMCS reports couldn\'t be copied; see below for the closest built-in ones.'],
        ['Run both side by side for a billing cycle.', 'On the server, php artisan whmcs:compare --simulate lists every renewal where this app and WHMCS would bill differently, without saving anything.'],
        ['Switch over.', 'Turn off the WHMCS cron, move payment gateway webhooks here, point your support email addresses at this app, set real MAIL_* settings and tell clients the new address.'],
    ] as $i => [$title, $detail])
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-700" aria-hidden="true">{{ $i + 1 }}</span>
            <div><strong>{{ $title }}</strong> <span class="text-ink-600">{{ $detail }}</span></div>
        </li>
    @endforeach
</ol>

<div class="mt-6">
    @include('admin.whmcs-import._custom-reports')
</div>

<div class="flex flex-wrap gap-3">
    <a href="{{ route('admin.clients.index') }}" class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">See your clients</a>
    @can('manage-staff')
        <a href="{{ route('admin.staff.index') }}" class="rounded-full px-4 py-2 font-medium text-brand-700 ring-1 ring-brand-500 hover:bg-brand-50">Check staff</a>
    @endcan
</div>
