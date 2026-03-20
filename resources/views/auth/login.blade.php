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
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(57,150,211,0.16)_0%,transparent_24%),radial-gradient(circle_at_top_right,rgba(143,192,67,0.16)_0%,transparent_20%),radial-gradient(circle_at_50%_0%,rgba(249,177,60,0.12)_0%,transparent_22%),linear-gradient(180deg,#ffffff_0%,#f5f9fc_58%,#eef4f9_100%)] text-slate-900 dark:bg-[radial-gradient(circle_at_top_left,rgba(57,150,211,0.18)_0%,transparent_22%),radial-gradient(circle_at_top_right,rgba(143,192,67,0.14)_0%,transparent_18%),linear-gradient(180deg,#1c203d_0%,#171b33_58%,#121626_100%)] dark:text-slate-100">
    <div class="fixed left-4 right-4 top-4 z-20 flex justify-start sm:left-6 sm:right-6 sm:top-6">
        <div class="inline-flex items-center gap-3 rounded-2xl border border-[#3996d3]/18 bg-white/84 px-3 py-2 shadow-sm shadow-slate-200/45 backdrop-blur dark:border-white/10 dark:bg-slate-950/76 dark:shadow-slate-950/40">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl border border-[#3996d3]/18 bg-white/92 p-2 shadow-sm dark:border-white/10 dark:bg-slate-900">
                <x-brand.logo variant="mark" class="h-full w-auto" />
            </div>
            <div class="min-w-0">
                <div class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-900 dark:text-slate-100">ANBG</div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400">Espace invite</div>
            </div>
        </div>
    </div>

    <main class="mx-auto grid min-h-screen w-[min(460px,94vw)] place-items-center px-1 py-24 sm:py-28">
        <section class="w-full rounded-2xl border border-slate-200/90 bg-white/95 p-5 shadow-lg shadow-slate-200/60 backdrop-blur sm:p-7 dark:border-slate-800 dark:bg-slate-900/90 dark:shadow-slate-950/50">
            <div class="mb-5">
                <x-brand.logo variant="wordmark" class="w-full max-w-[11.5rem] sm:max-w-[13rem] h-auto" />
            </div>
            <h1 class="mb-2 text-2xl font-semibold text-slate-900 dark:text-slate-100">Connexion</h1>
            <p class="mb-6 text-slate-600 dark:text-slate-300">Application de suivi PAS / PAO / PTA</p>

            @if ($errors->any())
                <div class="flash-error text-sm">
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
                        class="w-full rounded-lg border border-[#3996d3]/18 bg-[linear-gradient(135deg,rgba(255,255,255,0.99)_0%,rgba(245,249,252,0.96)_100%)] px-3 py-2 text-slate-900 placeholder:text-slate-400 shadow-[inset_0_1px_2px_rgba(15,23,42,0.04)] focus:border-[#3996d3] focus:outline-none focus:ring-2 focus:ring-[#3996d3]/20 dark:border-white/10 dark:bg-none dark:bg-[linear-gradient(135deg,rgba(10,20,46,0.96)_0%,rgba(18,35,72,0.92)_100%)] dark:text-slate-100 dark:placeholder:text-slate-400 dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(57,150,211,0.42)] dark:focus:border-[#3996d3]/55 dark:focus:ring-[#3996d3]/30"
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
                        class="w-full rounded-lg border border-[#3996d3]/18 bg-[linear-gradient(135deg,rgba(255,255,255,0.99)_0%,rgba(245,249,252,0.96)_100%)] px-3 py-2 text-slate-900 placeholder:text-slate-400 shadow-[inset_0_1px_2px_rgba(15,23,42,0.04)] focus:border-[#3996d3] focus:outline-none focus:ring-2 focus:ring-[#3996d3]/20 dark:border-white/10 dark:bg-none dark:bg-[linear-gradient(135deg,rgba(10,20,46,0.96)_0%,rgba(18,35,72,0.92)_100%)] dark:text-slate-100 dark:placeholder:text-slate-400 dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(57,150,211,0.42)] dark:focus:border-[#3996d3]/55 dark:focus:ring-[#3996d3]/30"
                        required
                    >
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300" for="remember">
                    <input id="remember" name="remember" type="checkbox" value="1" class="h-4 w-4">
                    Se souvenir de moi
                </label>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-[linear-gradient(135deg,#3996D3_0%,#1C203D_100%)] px-4 py-2.5 text-sm font-medium text-white shadow-[0_16px_30px_-20px_rgba(57,150,211,0.82)] transition hover:brightness-105"
                >
                    Se connecter
                </button>
            </form>

            <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                Compte administrateur: <code class="rounded bg-slate-100 px-1 dark:bg-slate-800 dark:text-slate-100">admin@anbg.ga</code> /
                <code class="rounded bg-slate-100 px-1 dark:bg-slate-800 dark:text-slate-100">Pass@12345</code>
            </p>
        </section>
    </main>
</body>
</html>
