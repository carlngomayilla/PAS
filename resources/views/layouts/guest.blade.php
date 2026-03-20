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
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(57,150,211,0.16)_0%,transparent_25%),radial-gradient(circle_at_top_right,rgba(143,192,67,0.14)_0%,transparent_20%),radial-gradient(circle_at_50%_0%,rgba(249,177,60,0.12)_0%,transparent_22%),linear-gradient(180deg,#ffffff_0%,#f5f9fc_52%,#eef4f9_100%)] text-slate-900 dark:bg-[radial-gradient(circle_at_top_left,rgba(57,150,211,0.18)_0%,transparent_24%),radial-gradient(circle_at_top_right,rgba(143,192,67,0.14)_0%,transparent_18%),linear-gradient(180deg,#1c203d_0%,#171b33_58%,#121626_100%)] dark:text-slate-100">
    <div class="pointer-events-none fixed left-4 right-4 top-4 z-20 flex justify-start sm:left-6 sm:right-6 sm:top-6">
        <div class="pointer-events-auto inline-flex items-center gap-3 rounded-2xl border border-[#3996d3]/18 bg-white/84 px-3 py-2 shadow-sm shadow-slate-200/45 backdrop-blur dark:border-white/10 dark:bg-slate-950/76 dark:shadow-slate-950/40">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl border border-[#3996d3]/18 bg-white/92 p-2 shadow-sm dark:border-white/10 dark:bg-slate-900">
                <x-brand.logo variant="mark" class="h-full w-auto" />
            </div>
            <div class="min-w-0">
                <div class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-900 dark:text-slate-100">ANBG</div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400">Espace invite</div>
            </div>
        </div>
    </div>
    @yield('content')
    @stack('scripts')
</body>
</html>
