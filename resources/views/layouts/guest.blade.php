<!DOCTYPE html>
<html lang="{{ $platformSettings->htmlLang() }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.app-icons')
    <script @cspNonce>
        (function () {
            var root = document.documentElement;
            /* Force light mode — dark mode is disabled */
            root.classList.remove('dark');
            root.setAttribute('data-theme', 'light');
            try {
                localStorage.setItem('anbg-theme', 'light');
                localStorage.setItem('theme', 'light');
                localStorage.setItem('anbg:theme', 'light');
            } catch (error) {}
        })();
    </script>
    <title>{{ $title ?? $platformSettings->get('login_page_title', 'Connexion - PAS') }}</title>
    @include('partials.vite-assets', ['profile' => 'guest'])
    <style>
        body {
            background: var(--app-body-bg-light, #ffffff);
        }
    </style>
    @stack('head')
</head>
<body class="min-h-screen text-slate-900">
    @yield('content')
    <div class="guest-footer pointer-events-none fixed inset-x-0 bottom-4 z-10 px-4 text-center text-[11px] text-slate-500">
        {{ $platformSettings->get('footer_text', 'ANBG | Système institutionnel de pilotage PAS / PAO / PTA') }}
    </div>
    @stack('scripts')
</body>
</html>
