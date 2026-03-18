<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - PAS</title>
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
    @include('partials.vite-assets')
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(52,184,255,0.18)_0%,transparent_24%),radial-gradient(circle_at_top_right,rgba(199,229,75,0.18)_0%,transparent_20%),radial-gradient(circle_at_50%_0%,rgba(246,182,58,0.12)_0%,transparent_22%),linear-gradient(180deg,#ffffff_0%,#eef8ff_58%,#eaf4ff_100%)] text-slate-900 dark:bg-[radial-gradient(circle_at_top_left,rgba(52,184,255,0.18)_0%,transparent_22%),radial-gradient(circle_at_top_right,rgba(199,229,75,0.14)_0%,transparent_18%),linear-gradient(180deg,#041125_0%,#0f172a_58%,#111827_100%)] dark:text-slate-100">
    <main class="mx-auto grid min-h-screen w-[min(460px,94vw)] place-items-center py-6">
        <section class="w-full rounded-2xl border border-slate-200/90 bg-white/95 p-7 shadow-lg shadow-slate-200/60 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 dark:shadow-slate-950/50">
            <div class="mb-5">
                <x-brand.logo variant="wordmark" class="w-full max-w-[15rem] text-slate-900 dark:text-slate-100" />
            </div>
            <h1 class="mb-2 text-2xl font-semibold text-slate-900 dark:text-slate-100">Connexion</h1>
            <p class="mb-6 text-slate-600 dark:text-slate-300">Application de suivi PAS / PAO / PTA</p>

            @if ($errors->any())
                <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 shadow-sm dark:border-red-900/70 dark:bg-red-950/50 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-3">
                @csrf

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Email</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        class="w-full rounded-lg border border-sky-100 bg-[linear-gradient(135deg,rgba(255,255,255,0.98)_0%,rgba(243,250,255,0.95)_100%)] px-3 py-2 text-slate-900 placeholder:text-slate-400 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200/80 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:placeholder:text-slate-400 dark:focus:border-sky-400 dark:focus:ring-sky-400/30"
                        required
                        autofocus
                    >
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Mot de passe</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        class="w-full rounded-lg border border-sky-100 bg-[linear-gradient(135deg,rgba(255,255,255,0.98)_0%,rgba(243,250,255,0.95)_100%)] px-3 py-2 text-slate-900 placeholder:text-slate-400 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200/80 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:placeholder:text-slate-400 dark:focus:border-sky-400 dark:focus:ring-sky-400/30"
                        required
                    >
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300" for="remember">
                    <input id="remember" name="remember" type="checkbox" value="1" class="h-4 w-4 rounded border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                    Se souvenir de moi
                </label>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-[linear-gradient(135deg,#34B8FF_0%,#1586D4_48%,#162566_100%)] px-4 py-2.5 text-sm font-medium text-white shadow-[0_16px_30px_-20px_rgba(21,134,212,0.92)] transition hover:brightness-105"
                >
                    Se connecter
                </button>
            </form>

            <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                Compte de test: <code class="rounded bg-slate-100 px-1 dark:bg-slate-800 dark:text-slate-100">admin@anbg.test</code> /
                <code class="rounded bg-slate-100 px-1 dark:bg-slate-800 dark:text-slate-100">Pass@12345</code>
            </p>
        </section>
    </main>
</body>
</html>
