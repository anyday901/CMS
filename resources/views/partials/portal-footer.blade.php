{{-- Themes can replace this partial to add their own footer. --}}
@unless (config('branding.hide_powered_by'))
    <footer class="mx-auto max-w-5xl px-4 pb-8 text-center text-xs text-ink-500">
        {!! __('Powered by :name', ['name' => '<a href="https://github.com/anyday901/CMS" class="hover:text-ink-700" rel="noopener">CMS</a>']) !!}
    </footer>
@endunless
