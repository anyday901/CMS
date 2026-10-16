@php $component = $layout === 'admin' ? 'admin-layout' : 'portal-layout'; @endphp
<x-dynamic-component :component="$component" :title="__('Two-factor authentication')">
    <div class="mx-auto mt-16 max-w-sm rounded-3xl bg-white p-7 shadow-[0_10px_30px_-12px_rgb(14_124_107/0.35)]">
        <div class="mb-4 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</div>
        <h1 class="mb-2 text-2xl">{{ __('Enter your code') }}</h1>
        <p class="mb-6 text-sm text-ink-600">{{ __('Open your authenticator app and type the six-digit code for :app. Lost your phone? Type one of your recovery codes instead.', ['app' => config('app.name')]) }}</p>
        <form method="POST" action="{{ $action }}" class="space-y-4 text-sm">
            @csrf
            <label class="block">{{ __('Code') }}
                <input name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="30" class="mt-1 w-full rounded-md px-3 py-2 font-mono text-lg tracking-widest ring-1 ring-ink-300">
            </label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Log in') }}</button>
            <a href="{{ $back }}" class="block text-center text-brand-600">{{ __('Use a different account') }}</a>
        </form>
    </div>
</x-dynamic-component>
