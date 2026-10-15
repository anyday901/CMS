@php
    $themes = app(App\Themes\ThemeManager::class);
    $theme = $themes->active();
    $css = $themes->css();
@endphp
@if ($css !== '')
    <style>{{ $css }}</style>
@endif
@if (! ($admin ?? false) && $theme->stylesheet)
    <link rel="stylesheet" href="{{ route('theme.asset', [$theme->key, $theme->stylesheet]) }}?v={{ urlencode($theme->version) }}">
@endif
