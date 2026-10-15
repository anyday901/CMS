@props(['form'])
@if (App\Security\Captcha::enabledFor($form))
    @php $captcha = App\Security\Captcha::provider(); @endphp
    <script src="{{ $captcha['script'] }}" async defer></script>
    <div class="{{ $captcha['class'] }}" data-sitekey="{{ config('security.captcha.site_key') }}"></div>
@endif
