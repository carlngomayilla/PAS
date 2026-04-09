<!DOCTYPE html>
<html lang="{{ $platformSettings->htmlLang() }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ $platformSettings->faviconUrl() }}">
    @php
        $defaultTheme = $appearanceSettings->get('default_theme', 'dark');
    @endphp
    <script>
        (function () {
            var themeKey = 'anbg-theme';
            var root = document.documentElement;
            var theme = @json($defaultTheme);

            try {
                var savedTheme = localStorage.getItem(themeKey);
                if (savedTheme === 'light' || savedTheme === 'dark') {
                    theme = savedTheme;
                }
            } catch (error) {
                theme = @json($defaultTheme);
            }

            root.classList.toggle('dark', theme === 'dark');
            root.setAttribute('data-theme', theme);
        })();
    </script>
    <title>{{ $title ?? $platformSettings->get('login_page_title', 'Connexion - PAS') }}</title>
    @include('partials.vite-assets')
    <style>
        body {
            background: var(--app-body-bg-light);
        }

        html.dark body {
            background: var(--app-body-bg-dark);
        }
    </style>
    @stack('head')
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
    @yield('content')
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-10 px-4 text-center text-[11px] text-slate-500 dark:text-slate-400">
        {{ $platformSettings->get('footer_text', 'ANBG | Systeme institutionnel de pilotage PAS / PAO / PTA') }}
    </div>
    @stack('scripts')
</body>
</html>
