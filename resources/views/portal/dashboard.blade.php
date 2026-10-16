@php use App\Support\Money; @endphp
<x-portal-layout :title="__('Home')">
    <h1 class="mb-6 text-2xl font-semibold">{{ __('Welcome, :name', ['name' => $contact?->first_name ?? $client->first_name]) }}</h1>
    @if ($contact)
        <p class="-mt-4 mb-6 text-sm text-ink-600">{{ __("You're signed in to :account's account as a contact.", ['account' => $client->company ?: $client->fullName()]) }}</p>
    @endif

    @foreach ($issues as $issue)
        <div class="mb-4 rounded-lg border border-orange-200 bg-orange-50 p-4 text-sm text-orange-900">
            <strong>{{ __($issue->statusLabel()) }}: {{ $issue->title }}</strong> {{ __('(affects :what).', ['what' => __($issue->affects())]) }}
            <a href="{{ route('network.index') }}" class="font-medium text-brand-700">{{ __('See network status') }}</a>
        </div>
    @endforeach

    @if ($can['billing'] && $client->credit_balance > 0)
        <p class="mb-4 text-sm text-ink-700">{!! __('You have :amount of account credit, which you can use on any unpaid invoice.', ['amount' => '<strong>'.e(Money::format($client->credit_balance, $client->currency)).'</strong>']) !!}</p>
    @endif

    @if ($can['billing'] && $unpaid->isNotEmpty())
        <div class="mb-8 rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm">
            {!! trans_choice('You have :count unpaid invoice totaling :amount.|You have :count unpaid invoices totaling :amount.', $unpaid->count(), ['amount' => '<strong>'.e(Money::format($unpaid->sum(fn ($i) => $i->balance()), $client->currency)).'</strong>']) !!}
            <a href="{{ route('portal.invoices.show', $unpaid->first()) }}" class="font-medium text-brand-600">{{ __('View the next one due') }}</a>
        </div>
    @endif

    @if ($can['services'])
        <h2 class="mb-3 text-lg font-semibold">{{ __('Your services') }}</h2>
        @if ($services->isEmpty())
            <p class="mb-8 text-sm text-ink-500">{{ __('You have no active services.') }}</p>
        @else
            <div class="mb-8">@include('portal.services._table')</div>
        @endif
    @endif

    @if ($can['billing'])
        <h2 class="mb-3 text-lg font-semibold">{{ __('Unpaid invoices') }}</h2>
        @if ($unpaid->isEmpty())
            <p class="text-sm text-ink-500">{{ __("You're all paid up.") }}</p>
        @else
            @include('portal.invoices._table', ['invoices' => $unpaid])
        @endif
    @endif

    @if ($contact && ! in_array(true, $can, true))
        <p class="text-sm text-ink-600">{!! __("Your login doesn't include invoices, services, orders or support yet. Ask the account holder to give you what you need.") !!}</p>
    @endif

    @if ($announcements->isNotEmpty())
        <div class="mt-8 mb-3 flex items-baseline gap-3">
            <h2 class="text-lg font-semibold">{{ __('Announcements') }}</h2>
            <a href="{{ route('announcements.index') }}" class="text-sm text-brand-600">{{ __('See all') }}</a>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($announcements as $announcement)
                <a href="{{ route('announcements.show', $announcement) }}" class="block rounded-lg border border-ink-200 bg-white p-4 text-sm hover:ring-1 hover:ring-brand-300">
                    <span class="text-ink-500">{{ $announcement->published_at->translatedFormat('M j, Y') }}</span>
                    <span class="block font-semibold">{{ $announcement->title }}</span>
                    <span class="mt-1 block text-ink-600">{{ App\Support\Markdown::excerpt($announcement->body, 110) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($can['billing'] && $credit->isNotEmpty())
        <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Account credit') }}</h2>
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2">{{ __('What') }}</th><th class="px-4 py-2 text-right">{{ __('Change') }}</th><th class="px-4 py-2 text-right">{{ __('Balance') }}</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($credit as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ $entry->created_at->translatedFormat('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ $entry->description }}</td>
                            <td class="px-4 py-2 text-right">{{ $entry->amount > 0 ? '+' : '' }}{{ Money::format($entry->amount, $client->currency) }}</td>
                            <td class="px-4 py-2 text-right">{{ Money::format($entry->balance_after, $client->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-portal-layout>
