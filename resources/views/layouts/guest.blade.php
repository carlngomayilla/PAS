<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            var themeKey = 'anbg-theme';
            var root = document.documentElement;
            var theme = 'dark';

            try {
                var savedTheme = localStorage.getItem(themeKey);
                if (savedTheme === 'light' || savedTheme === 'dark') {
                    theme = savedTheme;
                }
            } catch (error) {
                theme = 'dark';
            }

            root.classList.toggle('dark', theme === 'dark');
            root.setAttribute('data-theme', theme);
        })();
    </script>
    <title>{{ $title ?? 'Connexion - PAS' }}</title>
    @include('partials.vite-assets')
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    @yield('content')
    @stack('scripts')
</body>
</html>
