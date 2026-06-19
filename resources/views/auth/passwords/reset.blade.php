<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reinitialisation du mot de passe - ANBG Pilotage</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f8fafc; color: #0f172a;
            -webkit-font-smoothing: antialiased;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 24px;
        }
        .auth-card {
            width: 100%; max-width: 460px; background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: 18px; padding: 36px 32px;
            box-shadow: 0 20px 50px -25px rgba(15, 23, 42, 0.18);
        }
        .auth-brand { text-align: center; margin-bottom: 24px; font-weight: 800; color: #3996d3; font-size: 18px; }
        h1 { font-size: 22px; font-weight: 700; margin: 0 0 8px; color: #0f172a; }
        .desc { color: #475569; font-size: 14px; line-height: 1.55; margin: 0 0 24px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin: 14px 0 6px; }
        input[type=email], input[type=password], input[type=text] {
            width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 10px;
            font-size: 14px; outline: none; transition: border-color .15s ease, box-shadow .15s ease;
        }
        input:focus { border-color: #3996d3; box-shadow: 0 0 0 4px rgba(57, 150, 211, 0.15); }
        .password-field { position: relative; }
        .password-field input { padding-right: 72px; }
        .password-toggle {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            border: 0; background: transparent; color: #3996d3; cursor: pointer;
            font-size: 12px; font-weight: 700;
        }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 12px 22px; border-radius: 10px; font-weight: 600; font-size: 14px;
            background: #3996d3; color: #fff; border: none; cursor: pointer;
            box-shadow: 0 6px 16px -6px rgba(57, 150, 211, 0.55);
            transition: background .15s ease, transform .15s ease; margin-top: 22px;
        }
        .btn:hover { background: #2680c0; transform: translateY(-1px); }
        .alert {
            padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 18px;
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
        }
        .policy {
            margin-top: 16px; padding: 10px 14px; background: #f1f5f9; border-radius: 10px;
            font-size: 12px; color: #475569; line-height: 1.55;
        }
        .links { margin-top: 22px; text-align: center; font-size: 13px; color: #475569; }
        .links a { color: #3996d3; text-decoration: none; font-weight: 600; }
        .footer { margin-top: 24px; text-align: center; font-size: 11px; color: #94a3b8; }
    </style>
</head>
<body>
    <main class="auth-card" role="main">
        <p class="auth-brand">ANBG Pilotage</p>
        <h1>Nouveau mot de passe</h1>
        <p class="desc">Definissez votre nouveau mot de passe. Il devra respecter la politique de securite de l'application.</p>

        @if ($errors->any())
            <div class="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">Adresse e-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">

            <label for="password">Nouveau mot de passe</label>
            <div class="password-field">
                <input id="password" type="password" name="password" required autocomplete="new-password">
                <button type="button" class="password-toggle" data-password-toggle="password">Voir</button>
            </div>

            <label for="password_confirmation">Confirmer le mot de passe</label>
            <div class="password-field">
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                <button type="button" class="password-toggle" data-password-toggle="password_confirmation">Voir</button>
            </div>

            <div class="policy">
                <strong>Politique de securite :</strong> minimum 8 caracteres, lettres et chiffres requis. Majuscules et symboles sont acceptes mais non obligatoires. Les 5 derniers mots de passe ne peuvent pas etre reutilises.
            </div>

            <button class="btn" type="submit">Reinitialiser le mot de passe</button>
        </form>

        <div class="links">
            <a href="{{ route('login.form') }}">Retour a la connexion</a>
        </div>
        <p class="footer"><strong>ANBG Pilotage</strong> - PAS / PAO / PTA</p>
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
