@php
    use App\Models\AffiliateCommission;
    use App\Models\AffiliatePayout;
    use App\Support\Money;
    $minimum = $program->minimumPayout();
@endphp
<x-portal-layout :title="__('Affiliates')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('Affiliates') }}</h1>

    @if (! $affiliate)
        <div class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @if (! $program->enabled())
                <p class="text-ink-600">{{ __("Our affiliate program isn't open at the moment. Please check back later.") }}</p>
            @else
                @php $scope = $program->firstInvoiceOnly() ? __('of their first invoice') : __('of every invoice they pay'); @endphp
                <p class="text-ink-700">{!! __('Earn money by telling people about us. Share your own link, and when someone signs up through it you earn :commission :scope.', [
                    'commission' => '<strong>'.e((new App\Models\Affiliate)->setRelation('client', $client)->commissionLabel()).'</strong>',
                    'scope' => e($program->months() ? __(':scope for :months', ['scope' => $scope, 'months' => trans_choice(':count month|:count months', $program->months())]) : $scope),
                ]) !!}</p>
                <p class="text-ink-600">{{ __('Commissions are approved :days after the invoice is paid, and you can ask for a payout once your balance reaches :amount.', ['days' => trans_choice(':count day|:count days', $program->clearingDays()), 'amount' => Money::format($minimum, $client->currency)]) }}</p>
                @if ($program->selfSignUp())
                    <form method="POST" action="{{ route('portal.affiliates.store') }}">@csrf
                        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Join the affiliate program') }}</button>
                    </form>
                @else
                    <p class="text-ink-600">{!! __("To join, please :link and we'll set you up.", ['link' => '<a href="'.e(route('portal.tickets.create')).'" class="text-brand-600">'.e(__('contact us')).'</a>']) !!}</p>
                @endif
            @endif
        </div>
    @else
        @unless ($affiliate->isActive())
            <div class="mb-4 rounded-lg border border-orange-200 bg-orange-50 p-4 text-sm text-orange-900">{!! __("Your affiliate account is turned off, so your link isn't being tracked. Please :link if you have questions.", ['link' => '<a href="'.e(route('portal.tickets.create')).'" class="font-medium text-brand-700">'.e(__('contact us')).'</a>']) !!}</div>
        @endunless
        @unless ($program->enabled())
            <div class="mb-4 rounded-lg border border-orange-200 bg-orange-50 p-4 text-sm text-orange-900">{{ __("The affiliate program is paused, so new referrals and commissions aren't being tracked right now. Your balance is safe.") }}</div>
        @endunless

        <div class="mb-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <label for="affiliate-link" class="block font-semibold">{{ __('Your referral link') }}</label>
            <p class="mb-2 text-ink-600">{{ __('Share it anywhere. You earn :commission :scope paid by people who sign up through it within :days days of clicking.', ['commission' => $affiliate->commissionLabel(), 'scope' => $program->firstInvoiceOnly() ? __('of the first invoice') : __('of every invoice'), 'days' => $program->cookieDays()]) }}</p>
            <div class="flex flex-wrap gap-2">
                <input id="affiliate-link" readonly value="{{ $affiliate->link() }}" onclick="this.select()" class="w-full max-w-md rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('affiliate-link').value); this.textContent = {{ Js::from(__('Copied')) }}" class="rounded-full px-3 py-2 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Copy link') }}</button>
            </div>
            <p class="mt-2 text-ink-500">{!! __('To send people to a particular page, add it to the end, such as :example.', ['example' => '<span class="font-mono">'.e($affiliate->link()).'?to=/store</span>']) !!}</p>
        </div>

        <div class="mb-8 grid gap-4 text-sm sm:grid-cols-4">
            <div class="rounded-lg border border-ink-200 bg-white p-4"><div class="text-ink-500">{{ __('Visits') }}</div><div class="text-xl font-semibold">{{ number_format($affiliate->visits) }}</div></div>
            <div class="rounded-lg border border-ink-200 bg-white p-4"><div class="text-ink-500">{{ __('Sign-ups') }}</div><div class="text-xl font-semibold">{{ number_format($referralCount) }}</div></div>
            <div class="rounded-lg border border-ink-200 bg-white p-4"><div class="text-ink-500">{{ __('Clearing') }}</div><div class="text-xl font-semibold">{{ collect($pending)->map(fn ($amount, $currency) => Money::format($amount, $currency))->join(', ') ?: Money::format(0, $client->currency) }}</div></div>
            <div class="rounded-lg border border-ink-200 bg-white p-4"><div class="text-ink-500">{{ __('Available') }}</div><div class="text-xl font-semibold">{{ collect($balances)->map(fn ($amount, $currency) => Money::format($amount, $currency))->join(', ') ?: Money::format(0, $client->currency) }}</div></div>
        </div>

        <h2 class="mb-3 text-lg font-semibold">{{ __('Payouts') }}</h2>
        <div class="mb-4 space-y-2 text-sm">
            @forelse ($balances as $currency => $amount)
                @if ($amount >= $minimum && $affiliate->isActive())
                    <form method="POST" action="{{ route('portal.affiliates.payouts.store') }}" class="flex flex-wrap items-center gap-3">@csrf
                        <input type="hidden" name="currency" value="{{ $currency }}">
                        <span>{!! __('You have :amount ready to be paid.', ['amount' => '<strong>'.e(Money::format($amount, $currency)).'</strong>']) !!}</span>
                        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Ask for a payout of :amount', ['amount' => Money::format($amount, $currency)]) }}</button>
                    </form>
                @else
                    <p class="text-ink-600">{{ __('You have :amount available. You can ask for a payout once it reaches :minimum.', ['amount' => Money::format($amount, $currency), 'minimum' => Money::format($minimum, $currency)]) }}</p>
                @endif
            @empty
                <p class="text-ink-600">{{ __('Nothing to pay out yet. You can ask for a payout once your available balance reaches :amount.', ['amount' => Money::format($minimum, $client->currency)]) }}</p>
            @endforelse
        </div>
        @if ($payouts->isNotEmpty())
            <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">{{ __('Asked for') }}</th><th class="px-4 py-2 text-right">{{ __('Amount') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($payouts as $payout)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2">{{ $payout->created_at->translatedFormat('M j, Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ Money::format($payout->amount, $payout->currency) }}</td>
                                <td class="px-4 py-2">
                                    @if ($payout->status === AffiliatePayout::PAID)
                                        {{ $payout->method === AffiliatePayout::CREDIT ? __('Paid :date to your account credit', ['date' => $payout->paid_at?->translatedFormat('M j, Y')]) : __('Paid :date', ['date' => $payout->paid_at?->translatedFormat('M j, Y')]) }}
                                    @elseif ($payout->status === AffiliatePayout::REJECTED)
                                        {{ $payout->notes ? __('Not paid: :reason. The amount is back in your balance.', ['reason' => $payout->notes]) : __('Not paid. The amount is back in your balance.') }}
                                    @else
                                        {{ __('Waiting to be paid') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h2 class="mb-3 text-lg font-semibold">{{ __('Commissions') }}</h2>
        @if ($commissions->isEmpty())
            <p class="mb-8 text-sm text-ink-500">{{ __('No commissions yet. You earn one each time someone you referred pays an invoice.') }}</p>
        @else
            <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2 text-right">{{ __('Amount') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($commissions as $commission)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2">{{ $commission->created_at->translatedFormat('M j, Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ Money::format($commission->amount, $commission->currency) }}</td>
                                <td class="px-4 py-2">{{ match ($commission->status) {
                                    AffiliateCommission::PENDING => __('Clearing until :date', ['date' => $commission->available_at?->translatedFormat('M j, Y')]),
                                    AffiliateCommission::APPROVED => $commission->payout_id ? __('In a payout request') : __('Available'),
                                    AffiliateCommission::PAID => __('Paid'),
                                    default => __('Cancelled (the invoice was refunded or the commission was voided)'),
                                } }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h2 class="mb-3 text-lg font-semibold">{{ __('Sign-ups') }}</h2>
        @if ($referrals->isEmpty())
            <p class="text-sm text-ink-500">{{ __('Nobody has signed up through your link yet.') }}</p>
        @else
            <p class="mb-2 text-sm text-ink-600">{{ __('For their privacy, we only show when each person signed up.') }}</p>
            <ul class="list-inside list-disc text-sm text-ink-700">
                @foreach ($referrals as $referral)
                    <li>{{ $referral->created_at->translatedFormat('M j, Y') }}</li>
                @endforeach
            </ul>
        @endif
    @endif
</x-portal-layout>
