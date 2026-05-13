@php
    $appIconVersion = 'anbg-flamme-20260513';
@endphp

<link rel="icon" type="image/png" href="{{ asset('favicon.png') }}?v={{ $appIconVersion }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}?v={{ $appIconVersion }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}?v={{ $appIconVersion }}">
<link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v={{ $appIconVersion }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}?v={{ $appIconVersion }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}?v={{ $appIconVersion }}">
<meta name="theme-color" content="#1c203d">
