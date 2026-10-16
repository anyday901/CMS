<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ config('billing.company.name') }}</title>
</head>
<body style="margin:0; background:#f3f7f6; font-family:Helvetica, Arial, sans-serif; color:#13322f;">
<div style="background:#f3f7f6; padding:24px 12px;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:16px; padding:28px; font-size:15px; line-height:1.55;">
        @php $brand = app(App\Themes\ThemeManager::class)->brandColor() ?? '#0e7c6b'; @endphp
        <div style="padding-bottom:12px; font-weight:bold; font-size:17px; color:{{ $brand }};">
            @if ($logo = App\Support\Branding::logoUrl())
                <img src="{{ $logo }}" alt="{{ config('billing.company.name') }}" style="max-height:48px; max-width:220px; border:0;">
            @else
                {{ config('billing.company.name') }}
            @endif
        </div>
        <div class="content">{!! $html !!}</div>
    </div>
</div>
</body>
</html>
