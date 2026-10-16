<x-portal-layout :title="__('Unsubscribe')">
    <div class="mx-auto max-w-md rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @if ($done)
            <h1 class="mb-2 text-xl font-semibold">{!! __("You're unsubscribed") !!}</h1>
            <p class="text-ink-600">{{ __("We won't send news and offers to :email any more. You'll still get emails about your account, such as invoices and support replies.", ['email' => $client->email]) }}</p>
            <p class="mt-4 text-ink-600">{!! __('Changed your mind? Turn them back on under Account in the :link.', ['link' => '<a href="'.e(route('portal.account.edit')).'" class="text-brand-600">'.e(__('client area')).'</a>']) !!}</p>
        @else
            <h1 class="mb-2 text-xl font-semibold">{{ __('Unsubscribe from news and offers?') }}</h1>
            <p class="mb-4 text-ink-600">{{ __("We'll stop sending marketing emails to :email. Emails about your account, such as invoices and support replies, still come.", ['email' => $client->email]) }}</p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Unsubscribe') }}</button>
            </form>
        @endif
    </div>
</x-portal-layout>
