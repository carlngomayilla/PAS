@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
    <section class="mb-4 app-screen-block overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_18px_48px_rgba(23,50,74,0.10)]">
        <div class="h-1.5 bg-[linear-gradient(90deg,#1c203d_0%,#3996d3_52%,#8fc043_100%)]"></div>

        <div class="bg-[linear-gradient(135deg,#ffffff_0%,#f8fbfd_58%,#eef6f9_100%)] px-5 py-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-[#17324a] text-lg font-bold text-white shadow-sm ring-4 ring-white">
                        {{ $user->profile_initials }}
                    </div>

                    <div class="min-w-0">
                        <span class="inline-flex rounded-full border border-[#3996d3]/25 bg-[#3996d3]/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-[#1f6f9f]">Mon profil</span>
                        <h1 class="mt-3 text-2xl font-bold leading-tight text-[#17324a] md:text-3xl">Paramètres personnels et sécurité</h1>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                            Règles de mot de passe : {{ $passwordPolicyHelp }}
                        </p>
                    </div>
                </div>

                <a class="btn btn-primary shrink-0 rounded-lg px-4 py-2.5 shadow-sm" href="{{ route('dashboard') }}">
                    Retour dashboard
                </a>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white/90 px-4 py-3 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-[#3996d3]"></span>
                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">Profil</p>
                    </div>
                    <p class="mt-2 truncate text-sm font-semibold text-[#17324a]">{{ $profil['role_label'] }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white/90 px-4 py-3 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-[#8fc043]"></span>
                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">Portée</p>
                    </div>
                    <p class="mt-2 truncate text-sm font-semibold text-[#17324a]">{{ $profil['scope'] }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white/90 px-4 py-3 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-[#f9b13c]"></span>
                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">Rattachement</p>
                    </div>
                    <p class="mt-2 truncate text-sm font-semibold text-[#17324a]">
                        {{ $user->direction?->libelle ?? 'Sans direction' }} / {{ $user->service?->libelle ?? 'Sans service' }}
                    </p>
                </div>
            </div>

            @if ($passwordExpired)
                <p class="mt-4 rounded-lg border border-[#f9b13c]/40 bg-[#fff8d6] px-4 py-3 text-sm text-[#1c203d]">
                    Mot de passe expire. Le renouvellement est obligatoire pour acceder aux autres modules.
                </p>
            @endif
        </div>
    </section>

    <section class="app-screen-stack mb-4">
        <article class="showcase-panel app-screen-block">
            <div class="mb-4 flex flex-wrap items-center gap-4">
                @if ($user->profile_photo_url)
                    <img src="{{ $user->profile_photo_url }}" alt="Photo de {{ $user->name }}" class="h-20 w-20 rounded-full object-cover ring-2 ring-white shadow-sm">
                @else
                    <span class="inline-flex h-20 w-20 items-center justify-center rounded-full bg-[#3996d3] text-2xl font-semibold text-white">
                        {{ $user->profile_initials }}
                    </span>
                @endif
                <div>
                    <p class="text-xl font-semibold text-slate-950">{{ $user->name }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $user->email }}</p>
                </div>
            </div>
            <div class="showcase-data-list">
                <div class="showcase-data-point">
                    <p class="showcase-data-key">Rôle</p>
                    <p class="showcase-data-value">{{ $profil['role_label'] }} ({{ $profil['role'] }})</p>
                </div>
                <div class="showcase-data-point">
                    <p class="showcase-data-key">Portée</p>
                    <p class="showcase-data-value">{{ $profil['scope'] }}</p>
                </div>
                <div class="showcase-data-point">
                    <p class="showcase-data-key">Direction</p>
                    <p class="showcase-data-value">{{ $user->direction?->libelle ?? 'Aucune' }}</p>
                </div>
                <div class="showcase-data-point">
                    <p class="showcase-data-key">Service</p>
                    <p class="showcase-data-value">{{ $user->service?->libelle ?? 'Aucun' }}</p>
                </div>
            </div>
        </article>

        <article class="showcase-panel app-screen-block">
            <h2 class="showcase-panel-title">Synthèse sécurité</h2>
            <div class="mt-4 showcase-summary-grid">
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">Sessions actives</p>
                    <p class="showcase-kpi-number">{{ count($activeSessions) }}</p>
                    <p class="showcase-kpi-meta">Accès web détectés</p>
                </article>
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">Mot de passe</p>
                    <p class="showcase-kpi-number text-[1.45rem]">{{ $passwordExpired ? 'Expire' : 'Valide' }}</p>
                    <p class="showcase-kpi-meta">{{ $passwordExpired ? 'Renouvellement requis' : 'Conforme a la politique actuelle' }}</p>
                </article>
            </div>
        </article>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <form method="POST" enctype="multipart/form-data" class="form-shell" action="{{ route('workspace.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Informations personnelles</h2>
                <div class="form-grid">
                    <div>
                        <label for="profile_photo">Photo de profil</label>
                        <div class="showcase-upload-zone">
                            <p class="text-sm font-semibold text-slate-800">Importer une nouvelle photo</p>
                            <p class="mt-1 text-xs text-slate-500">Formats acceptes: JPG, PNG, WEBP. Taille max 3 Mo.</p>
                            <input id="profile_photo" class="mt-4" name="profile_photo" type="file" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        @if ($user->profile_photo_url)
                            <label class="mt-3 !mb-0 inline-flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" name="remove_profile_photo" value="1" @checked(old('remove_profile_photo'))>
                                Supprimer la photo actuelle
                            </label>
                        @endif
                    </div>
                    <div>
                        <label for="name">Nom complet</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
                    </div>
                    <div>
                        <label for="email">Adresse email</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Sécurité</h2>
                <div class="form-grid">
                    <div>
                        <label for="current_password">Mot de passe actuel</label>
                        <div class="relative">
                            <input id="current_password" name="current_password" type="password" @required($passwordExpired)>
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="current_password">
                                Voir
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="password">Nouveau mot de passe</label>
                        <div class="relative">
                            <input id="password" name="password" type="password" @required($passwordExpired)>
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="password">
                                Voir
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="password_confirmation">Confirmation</label>
                        <div class="relative">
                            <input id="password_confirmation" name="password_confirmation" type="password" @required($passwordExpired)>
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="password_confirmation">
                                Voir
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">Enregistrer</button>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('dashboard') }}">Retour dashboard</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel app-screen-block">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Sessions actives</h2>
            </div>
            <form method="POST" action="{{ route('workspace.profile.sessions.revoke_others') }}">
                @csrf
                <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">
                    Révoquer toutes les autres sessions
                </button>
            </form>
        </div>

        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Statut</th>
                        <th>Adresse IP</th>
                        <th>Dernière activité</th>
                        <th>Client</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activeSessions as $session)
                        <tr>
                            <td>
                                @if ($session['is_current'])
                                    <span class="rounded-full bg-[#eef6e1] px-2 py-1 text-xs font-semibold text-[#1c203d]">Session courante</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">Active</span>
                                @endif
                            </td>
                            <td class="font-medium text-slate-800">{{ $session['ip_address'] }}</td>
                            <td>
                                {{ $session['last_activity']?->format('d/m/Y H:i:s') ?? 'N/A' }}
                            </td>
                            <td>{{ $session['user_agent'] }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ $session['is_current'] ? route('workspace.profile.sessions.revoke_current') : route('workspace.profile.sessions.revoke', $session['id']) }}">
                                    @csrf
                                    <button class="btn btn-primary btn-sm" type="submit">
                                        {{ $session['is_current'] ? 'Fermer cette session' : 'Révoquer' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state title="Aucune session" message="Aucune session active détectée." icon="lock" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    </div>

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
@endsection
