<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié — ANBG Pilotage</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }
        .auth-card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 36px 32px;
            box-shadow: 0 20px 50px -25px rgba(15, 23, 42, 0.18);
        }
        .auth-brand { text-align: center; margin-bottom: 24px; font-weight: 800; color: #3996d3; font-size: 18px; }
        h1 { font-size: 22px; font-weight: 700; margin: 0 0 8px; color: #0f172a; }
        .desc { color: #475569; font-size: 14px; line-height: 1.55; margin: 0 0 24px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        input[type=email] {
            width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 10px;
            font-size: 14px; outline: none; transition: border-color .15s ease, box-shadow .15s ease;
        }
        input[type=email]:focus { border-color: #3996d3; box-shadow: 0 0 0 4px rgba(57, 150, 211, 0.15); }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 12px 22px; border-radius: 10px; font-weight: 600; font-size: 14px;
            background: #3996d3; color: #fff; border: none; cursor: pointer;
            box-shadow: 0 6px 16px -6px rgba(57, 150, 211, 0.55);
            transition: background .15s ease, transform .15s ease;
        }
        .btn:hover { background: #2680c0; transform: translateY(-1px); }
        .alert {
            padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 18px;
            background: #e8f3fb; color: #0f172a; border: 1px solid #bfdbfe;
        }
        .alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .links { margin-top: 22px; text-align: center; font-size: 13px; color: #475569; }
        .links a { color: #3996d3; text-decoration: none; font-weight: 600; }
        .links a:hover { text-decoration: underline; }
        .footer { margin-top: 24px; text-align: center; font-size: 11px; color: #94a3b8; }
    </style>
</head>
<body>
    <main class="auth-card" role="main">
        <p class="auth-brand">ANBG Pilotage</p>
        <h1>Mot de passe oublié ?</h1>
        <p class="desc">Saisissez votre adresse e-mail professionnelle. Si un compte existe, vous recevrez un lien de réinitialisation valable 60 minutes.</p>

        @if (session('status'))
            <div class="alert">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <label for="email">Adresse e-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            <div style="height: 18px"></div>
            <button class="btn" type="submit">Envoyer le lien</button>
        </form>

        <div class="links">
            <a href="{{ route('login.form') }}">← Retour à la connexion</a>
        </div>
        <p class="footer"><strong>ANBG Pilotage</strong> · PAS / PAO / PTA</p>
    </main>
</body>
</html>
