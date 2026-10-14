{{-- A service's software license: its key, status and where it is locked to, with a reissue button when $reissue is a URL. --}}
<dl class="grid gap-4 sm:grid-cols-3">
    <div class="sm:col-span-2"><dt class="text-ink-500">License key</dt><dd><code class="break-all text-base">{{ $license->key }}</code></dd></div>
    <div><dt class="text-ink-500">Status</dt><dd><x-status-badge :status="$license->status" /></dd></div>
    @foreach (['domains' => 'Domains', 'ips' => 'IP addresses', 'directories' => 'Directories'] as $field => $name)
        <div><dt class="text-ink-500">{{ $name }}</dt><dd>@forelse ($license->{$field} ?? [] as $value)<code class="block break-all">{{ $value }}</code>@empty<span class="text-ink-500">Not locked yet</span>@endforelse</dd></div>
    @endforeach
    <div><dt class="text-ink-500">Last checked</dt><dd>@if ($license->last_checked_at){{ $license->last_checked_at->format('M j, Y g:ia') }}<span class="text-ink-500">{{ $license->last_domain ? " from {$license->last_domain}" : '' }}{{ $license->last_ip ? " ({$license->last_ip})" : '' }}</span>@else<span class="text-ink-500">Never</span>@endif</dd></div>
    @isset($staff)
        <div><dt class="text-ink-500">Checks</dt><dd>{{ number_format($license->checks) }}, reissued {{ $license->reissues }} {{ Str::plural('time', $license->reissues) }}</dd></div>
    @endisset
</dl>
@if ($reissue ?? null)
    <form method="POST" action="{{ $reissue }}" class="mt-3" onsubmit="return confirm('Reissue this license? It stops being locked to where it is installed now.')">@csrf
        <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Reissue</button>
        <span class="ml-2 text-ink-500">Moving the software? A reissue frees the license, and it locks to the next place it is installed.</span>
    </form>
@endif
