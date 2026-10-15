{{-- Lighthouse replaces the portal footer with the company's details. --}}
<footer class="mx-auto max-w-5xl border-t border-ink-200 px-4 py-6 text-xs text-ink-500">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
        <span class="font-medium text-ink-700">{{ App\Support\Branding::companyName() }}</span>
        @if (config('billing.company.email'))<a href="mailto:{{ config('billing.company.email') }}" class="hover:text-ink-700">{{ config('billing.company.email') }}</a>@endif
        <a href="{{ route('kb.index') }}" class="hover:text-ink-700">{{ __('Help') }}</a>
        @unless (config('branding.hide_powered_by'))
            <span class="ml-auto">{!! __('Powered by :name', ['name' => '<a href="https://github.com/anyday901/CMS" class="hover:text-ink-700" rel="noopener">CMS</a>']) !!}</span>
        @endunless
    </div>
</footer>
