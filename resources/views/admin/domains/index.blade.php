@php use App\Support\Money; @endphp
<x-admin-layout title="Domains">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Domains</h1>
        <form class="ml-auto">
            @if ($status)<input type="hidden" name="status" value="{{ $status->value }}">@endif
            <label for="domain-search" class="sr-only">Search domains</label>
            <input id="domain-search" type="search" name="q" value="{{ $search }}" placeholder="Domain, client name or email" class="w-72 rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300">
        </form>
        @can('manage-settings')
            <a href="{{ route('admin.tlds.index') }}" class="rounded-full px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">TLDs and pricing</a>
            <a href="{{ route('admin.domain-settings.edit') }}" class="rounded-full px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Registrars</a>
        @endcan
    </div>
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <a href="{{ route('admin.domains.index', array_filter(['q' => $search])) }}" class="rounded-full px-3 py-1 {{ ! $status && ! $expiring ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">All</a>
        @foreach (App\Enums\DomainStatus::cases() as $case)
            <a href="{{ route('admin.domains.index', array_filter(['status' => $case->value, 'q' => $search])) }}" class="rounded-full px-3 py-1 {{ $status === $case ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $case->label() }}</a>
        @endforeach
        <a href="{{ route('admin.domains.index', ['expiring' => 1]) }}" class="rounded-full px-3 py-1 {{ $expiring ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">Expiring in 30 days</a>
    </div>
    @if ($failed)
        <p class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ $failed }} domain{{ $failed === 1 ? '' : 's' }} had a registrar request fail. Open {{ $failed === 1 ? 'it' : 'them' }} below, fix the cause and run the request again.</p>
    @endif
    @if ($domains->isEmpty())
        <p class="text-sm text-ink-500">No domains found.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Domain</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Registrar</th><th class="px-4 py-2">Expires</th><th class="px-4 py-2">Renewal</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($domains as $domain)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.domains.show', $domain) }}" class="font-medium text-brand-600">{{ $domain->name }}</a>
                                @if ($domain->registrar_status === 'failed')<div class="text-red-700">{{ ucfirst((string) $domain->registrar_action) }} failed</div>@endif</td>
                            <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $domain->client) }}" class="text-brand-600">{{ $domain->client->fullName() }}</a></td>
                            <td class="px-4 py-2">{{ $registrars->get($domain->registrar)?->label() ?? ($domain->registrar ?: '—') }}</td>
                            <td class="px-4 py-2">{{ $domain->expiry_date?->format('M j, Y') ?? '—' }}</td>
                            <td class="px-4 py-2">{{ Money::format($domain->recurring_amount, $domain->client->currency) }} / {{ $domain->years }} yr @unless ($domain->auto_renew)<span class="text-ink-500">(auto-renew off)</span>@endunless</td>
                            <td class="px-4 py-2"><x-status-badge :status="$domain->status">{{ $domain->status->label() }}</x-status-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $domains->links() }}</div>
    @endif
</x-admin-layout>
