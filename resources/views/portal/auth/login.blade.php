<x-portal-layout :title="__('Log in')">
    <div class="mx-auto mt-16 max-w-sm rounded-3xl bg-white p-7 shadow-[0_10px_30px_-12px_rgb(14_124_107/0.35)]">
        <div class="mb-4 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</div>
        <h1 class="mb-6 text-2xl">{{ __('Log in to your account') }}</h1>
        <form method="POST" action="{{ route('portal.login') }}" class="space-y-4 text-sm">
            @csrf
            <label class="block">{{ __('Email') }}
                <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">{{ __('Password') }}
                <input type="password" name="password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="flex items-center gap-2"><input type="checkbox" name="remember" value="1"> {{ __('Remember me') }}</label>
            <x-captcha form="login" />
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Log in') }}</button>
            <a href="{{ route('portal.password.request') }}" class="block text-center text-brand-600">{{ __('Forgot or never set your password?') }}</a>
            <p class="border-t border-ink-100 pt-4 text-center text-ink-600">{!! __('New here? :link and create an account when you order.', ['link' => '<a href="'.e(route('store.index')).'" class="text-brand-600">'.e(__('Browse our products')).'</a>']) !!}</p>
        </form>
    </div>
</x-portal-layout>
