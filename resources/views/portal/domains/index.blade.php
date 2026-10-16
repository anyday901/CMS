@php use App\Support\Money; @endphp
<x-portal-layout :title="__('Domains')">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ __('Domains') }}</h1>
        @if (App\Auth\PortalContact::allows('orders') && App\Models\Tld::sold()->exists())
            <a href="{{ route('domains.index') }}" class="ml-auto rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">{{ __('Register or transfer a domain') }}</a>
        @endif
    </div>
    @if ($domains->isEmpty())
        <p class="text-sm text-ink-500">{{ __('You have no domains yet.') }}</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500">
                    <tr><th class="px-4 py-2">{{ __('Domain') }}</th><th class="px-4 py-2">{{ __('Expires') }}</th><th class="px-4 py-2">{{ __('Auto-renew') }}</th><th class="px-4 py-2">{{ __('Renewal') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($domains as $domain)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('portal.domains.show', $domain) }}" class="text-brand-600">{{ $domain->name }}</a></td>
                            <td class="px-4 py-2">{{ $domain->expiry_date?->translatedFormat('M j, Y') ?? '—' }}@if ($domain->status->value === 'active' && $domain->daysLeft() !== null && $domain->daysLeft() <= 30) <span class="text-orange-700">({{ trans_choice(':count day left|:count days left', $domain->daysLeft()) }})</span>@endif</td>
                            <td class="px-4 py-2">{{ $domain->auto_renew ? __('On') : __('Off') }}</td>
                            <td class="px-4 py-2">{{ Money::format($domain->recurring_amount, auth('client')->user()->currency) }} <span class="text-ink-500">{{ trans_choice('every year|every :count years', $domain->years) }}</span></td>
                            <td class="px-4 py-2"><x-status-badge :status="$domain->status">{{ __($domain->status->label()) }}</x-status-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-portal-layout>
