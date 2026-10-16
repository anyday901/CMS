<x-portal-layout :title="__('Down for maintenance')">
    <div class="mx-auto mt-16 max-w-lg rounded-lg border border-ink-200 bg-white p-8 text-center">
        <h1 class="mb-3 text-2xl">{{ __('We\'ll be right back') }}</h1>
        <p class="text-ink-600">{{ filled($message) ? $message : __('We\'re doing some maintenance. Please check back shortly.') }}</p>
    </div>
</x-portal-layout>
