<!DOCTYPE html>
<html lang="{{ $platformSettings->htmlLang() }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $platformSettings->get('login_page_title', 'Connexion - PAS') }}</title>
    @include('partials.app-icons')
    <script @cspNonce>
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
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(36,59,90,0.08)_0%,transparent_24%),radial-gradient(circle_at_top_right,rgba(167,143,99,0.06)_0%,transparent_20%),linear-gradient(180deg,#ffffff_0%,#f6f8fb_58%,#eef2f6_100%)] text-slate-900 dark:text-slate-100 dark:bg-[radial-gradient(circle_at_top_left,rgba(36,59,90,0.14)_0%,transparent_22%),radial-gradient(circle_at_top_right,rgba(167,143,99,0.08)_0%,transparent_18%),linear-gradient(180deg,#162338_0%,#142033_58%,#0f1826_100%)]">

    <main class="mx-auto grid min-h-screen w-[min(460px,94vw)] place-items-center px-1 py-24 sm:py-28">
        <section class="login-card w-full rounded-2xl border border-slate-200/90 bg-white/95 p-5 shadow-lg shadow-slate-200/60 backdrop-blur sm:p-7">
            <h1 class="mb-2 text-2xl font-semibold text-slate-900">{{ $platformSettings->get('login_form_title', 'Connexion') }}</h1>
            <p class="mb-6 text-slate-600">{{ $platformSettings->get('login_form_subtitle', 'Application de suivi PAS / PAO / PTA') }}</p>

            @if ($errors->any())
                <div class="flash-error text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-3">
                @csrf

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-slate-700">{{ $platformSettings->get('login_identifier_label', 'Email ou matricule') }}</label>
                    <input
                        id="email"
                        name="email"
                        type="text"
                        value="{{ old('email') }}"
                        class="w-full rounded-lg border border-slate-300/70 bg-[linear-gradient(135deg,rgba(255,255,255,0.99)_0%,rgba(248,250,252,0.96)_100%)] px-3 py-2 text-slate-900 placeholder:text-slate-400 shadow-[inset_0_1px_2px_rgba(15,23,42,0.04)] focus:border-[#516B8B] focus:outline-none focus:ring-2 focus:ring-[#516B8B]/15 dark:border-slate-700 dark:text-slate-100 dark:placeholder:text-slate-500 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.96)_0%,rgba(22,35,56,0.92)_100%)] dark:shadow-[inset_0_1px_2px_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(36,59,90,0.34)]"
                        required
                        autofocus
                    >
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Mot de passe</label>
                    <div class="relative">
                        <input
                            id="password"
                            name="password"
                            type="password"
                            class="w-full rounded-lg border border-slate-300/70 bg-[linear-gradient(135deg,rgba(255,255,255,0.99)_0%,rgba(248,250,252,0.96)_100%)] px-3 py-2 pr-16 text-slate-900 placeholder:text-slate-400 shadow-[inset_0_1px_2px_rgba(15,23,42,0.04)] focus:border-[#516B8B] focus:outline-none focus:ring-2 focus:ring-[#516B8B]/15 dark:border-slate-700 dark:text-slate-100 dark:placeholder:text-slate-500 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.96)_0%,rgba(22,35,56,0.92)_100%)] dark:shadow-[inset_0_1px_2px_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(36,59,90,0.34)]"
                            required
                        >
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="password">
                            Voir
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm text-slate-700" for="remember">
                        <input id="remember" name="remember" type="checkbox" value="1" class="h-4 w-4">
                        Se souvenir de moi
                    </label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-sm font-medium text-[#3996d3] hover:underline">Mot de passe oublié ?</a>
                    @endif
                </div>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-[linear-gradient(135deg,#243B5A_0%,#516B8B_100%)] px-4 py-2.5 text-sm font-medium text-white shadow-[0_16px_30px_-20px_rgba(36,59,90,0.42)] transition hover:brightness-105"
                >
                    Se connecter
                </button>
            </form>
        </section>
    </main>
    <script @cspNonce>
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.dataset.passwordToggle);
                if (! input) {
                    return;
                }

                var isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                button.textContent = isHidden ? 'Cacher' : 'Voir';
            });
        });
    </script>
</body>
</html>
