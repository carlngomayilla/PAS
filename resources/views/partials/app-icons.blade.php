@php
    $appIconVersion = 'anbg-symbol-20260420';
    $faviconUrl = $platformSettings->faviconUrl();
    $markUrl = $platformSettings->brandAssetUrl('mark');
@endphp

<link rel="icon" type="image/png" href="{{ $faviconUrl }}?v={{ $appIconVersion }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ $faviconUrl }}?v={{ $appIconVersion }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrl }}?v={{ $appIconVersion }}">
<link rel="shortcut icon" href="{{ $faviconUrl }}?v={{ $appIconVersion }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ $markUrl }}?v={{ $appIconVersion }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}?v={{ $appIconVersion }}">
<meta name="theme-color" content="#1c203d">
