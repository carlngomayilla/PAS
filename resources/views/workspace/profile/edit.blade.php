@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow profile-page-flow">
    <x-ui.page-title title="Paramètres personnels et sécurité" eyebrow="Mon profil" subtitle="Informations du compte, mot de passe et sessions actives." class="app-screen-block" data-profile-page-header>
        <x-slot:actions><a class="btn btn-secondary" href="{{ route('dashboard') }}">Retour dashboard</a></x-slot:actions>
    </x-ui.page-title>
    @if ($passwordChangeRequired)
        <p class="app-screen-block rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100" role="alert">
            Mot de passe provisoire. Remplacez-le avant d acceder aux autres modules.
        </p>
    @endif

    <section class="app-screen-stack mb-4" aria-label="Compte et sécurité">
        <article class="showcase-panel app-screen-block profile-summary-card" aria-labelledby="profile-identity-title">
            <div class="profile-summary-identity flex flex-wrap items-center gap-4">
                @if ($user->profile_photo_url)
                    <img src="{{ $user->profile_photo_url }}" alt="Photo de {{ $user->name }}" class="h-20 w-20 rounded-full object-cover ring-2 ring-white shadow-sm">
                @else
                    <span class="inline-flex h-20 w-20 items-center justify-center rounded-full bg-[#3996d3] text-2xl font-semibold text-white">
                        {{ $user->profile_initials }}
                    </span>
                @endif
                <div class="min-w-0 flex-1">
                    <h2 id="profile-identity-title" class="break-words text-xl font-semibold text-[var(--app-text)]">{{ $user->name }}</h2>
                    <p class="mt-1 break-all text-sm text-[var(--app-muted)]">{{ $user->email }}</p>
                </div>
            </div>
            <dl class="showcase-data-list profile-summary-data" aria-label="Affectation et périmètre">
                <div class="showcase-data-point">
                    <dt class="showcase-data-key">Rôle</dt>
                    <dd class="showcase-data-value">{{ $profil['role_label'] }}</dd>
                </div>
                <div class="showcase-data-point">
                    <dt class="showcase-data-key">Portée</dt>
                    <dd class="showcase-data-value">{{ $profil['scope'] }}</dd>
                </div>
                <div class="showcase-data-point">
                    <dt class="showcase-data-key">Direction</dt>
                    <dd class="showcase-data-value">{{ $user->direction?->libelle ?? 'Aucune' }}</dd>
                </div>
                <div class="showcase-data-point">
                    <dt class="showcase-data-key">Service</dt>
                    <dd class="showcase-data-value">{{ $user->service?->libelle ?? 'Aucun' }}</dd>
                </div>
            </dl>
        </article>

        <article class="showcase-panel app-screen-block profile-security-card" aria-labelledby="profile-security-title">
            <h2 id="profile-security-title" class="showcase-panel-title">Synthèse sécurité</h2>
            <div class="mt-4 showcase-summary-grid">
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">Sessions actives</p>
                    <p class="showcase-kpi-number">{{ count($activeSessions) }}</p>
                    <p class="showcase-kpi-meta">Accès web détectés</p>
                </article>
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">Mot de passe</p>
                    <p class="showcase-kpi-number text-[1.45rem]">{{ $passwordChangeRequired ? 'À modifier' : 'Valide' }}</p>
                    <p class="showcase-kpi-meta">{{ $passwordChangeRequired ? 'Mot de passe provisoire' : 'Conforme a la politique actuelle' }}</p>
                </article>
            </div>
        </article>
    </section>

    <section class="mb-4 app-screen-block">
        <form method="POST" enctype="multipart/form-data" class="form-shell reco-form-shell profile-form-shell" action="{{ route('workspace.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="form-section reco-form-section">
                <h2 class="form-section-title">Informations personnelles</h2>
                <div class="form-grid reco-form-grid profile-personal-fields">
                    <div>
                        <label for="name">Nom complet</label>
                        <input id="name" name="name" type="text" autocomplete="name" value="{{ old('name', $user->name) }}" required>
                    </div>
                    <div>
                        <label for="email">Adresse email</label>
                        <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email', $user->email) }}" required>
                    </div>
                    <details class="action-advanced-filters col-span-full" data-profile-photo-options @if ($errors->has('profile_photo') || old('remove_profile_photo')) open @endif>
                        <summary class="cursor-pointer font-semibold text-[var(--app-text)]">Photo de profil (facultatif)</summary>
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
                    </details>
                </div>
            </div>

            <details class="form-section reco-form-section action-advanced-filters" data-profile-security-options @if ($passwordChangeRequired || $errors->hasAny(['current_password', 'password', 'password_confirmation'])) open @endif>
                <summary class="form-section-title cursor-pointer">Changer le mot de passe{{ $passwordChangeRequired ? ' — obligatoire' : ' (facultatif)' }}</summary>
                <p class="mb-4 text-sm text-[var(--app-muted)]">Règles de mot de passe : {{ $passwordPolicyHelp }}</p>
                <div class="form-grid reco-form-grid">
                    <div>
                        <label for="current_password">Mot de passe actuel</label>
                        <div class="relative">
                            <input id="current_password" name="current_password" type="password" autocomplete="current-password" class="pr-14" @required($passwordChangeRequired)>
                            <x-auth.password-toggle target="current_password" />
                        </div>
                    </div>
                    <div>
                        <label for="password">Nouveau mot de passe</label>
                        <div class="relative">
                            <input id="password" name="password" type="password" autocomplete="new-password" class="pr-14" @required($passwordChangeRequired)>
                            <x-auth.password-toggle target="password" />
                        </div>
                    </div>
                    <div>
                        <label for="password_confirmation">Confirmation</label>
                        <div class="relative">
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="pr-14" @required($passwordChangeRequired)>
                            <x-auth.password-toggle target="password_confirmation" />
                        </div>
                    </div>
                </div>
            </details>

            <div class="form-actions reco-form-footer">
                <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">Enregistrer</button>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('dashboard') }}">Retour dashboard</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel app-screen-block profile-sessions-card">
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
            <table class="app-table data-table" data-mobile-cards aria-label="Sessions actives du compte">
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
                            <td data-label="Statut">
                                @if ($session['is_current'])
                                    <span class="profile-session-status rounded-full px-2 py-1 text-xs font-semibold">Session courante</span>
                                @else
                                    <span class="profile-session-status rounded-full px-2 py-1 text-xs font-semibold">Active</span>
                                @endif
                            </td>
                            <td data-label="Adresse IP" class="break-all font-medium text-slate-800">{{ $session['ip_address'] }}</td>
                            <td data-label="Dernière activité">
                                {{ $session['last_activity']?->format('d/m/Y H:i:s') ?? 'N/A' }}
                            </td>
                            <td data-label="Client" class="break-all">{{ $session['user_agent'] }}</td>
                            <td data-label="Action" class="text-right">
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
@endsection
