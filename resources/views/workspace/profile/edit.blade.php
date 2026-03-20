@extends('layouts.workspace')

@section('content')
    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">Mon profil</span>
                <h1 class="showcase-title">Parametres personnels et securite</h1>
                <p class="showcase-subtitle">
                    Mise a jour de vos informations, de votre photo de profil et de vos acces actifs.
                    Les regles de mot de passe appliquees a ce compte sont: {{ $passwordPolicyHelp }}
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        {{ $profil['role_label'] }}
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        {{ $profil['scope'] }}
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#8fc043]"></span>
                        {{ $user->direction?->libelle ?? 'Sans direction' }} / {{ $user->service?->libelle ?? 'Sans service' }}
                    </span>
                </div>
                @if ($passwordExpired)
                    <p class="mt-4 rounded-2xl border border-[#f9b13c]/40 bg-[#fff8d6] px-4 py-3 text-sm text-[#1c203d] dark:border-[#f9b13c]/35 dark:bg-[#f9b13c]/10 dark:text-[#f8e932]">
                        Mot de passe expire. Le renouvellement est obligatoire pour acceder aux autres modules.
                    </p>
                @endif
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('dashboard') }}">
                    Retour dashboard
                </a>
            </div>
        </div>
    </section>

    <section class="mb-4 grid gap-4 xl:grid-cols-[1.1fr_1fr]">
        <article class="showcase-panel">
            <div class="mb-4 flex flex-wrap items-center gap-4">
                @if ($user->profile_photo_url)
                    <img src="{{ $user->profile_photo_url }}" alt="Photo de {{ $user->name }}" class="h-20 w-20 rounded-full object-cover ring-2 ring-white shadow-sm">
                @else
                    <span class="inline-flex h-20 w-20 items-center justify-center rounded-full bg-slate-900 text-2xl font-semibold text-white">
                        {{ $user->profile_initials }}
                    </span>
                @endif
                <div>
                    <p class="text-xl font-semibold text-slate-950 dark:text-slate-50">{{ $user->name }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                </div>
            </div>
            <div class="showcase-data-list">
                <div class="showcase-data-point">
                    <p class="showcase-data-key">Role</p>
                    <p class="showcase-data-value">{{ $profil['role_label'] }} ({{ $profil['role'] }})</p>
                </div>
                <div class="showcase-data-point">
                    <p class="showcase-data-key">Portee</p>
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

        <article class="showcase-panel">
            <h2 class="showcase-panel-title">Synthese securite</h2>
            <p class="showcase-panel-subtitle">Vue rapide de votre hygiene de compte et des acces en cours.</p>
            <div class="mt-4 showcase-summary-grid">
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">Sessions actives</p>
                    <p class="showcase-kpi-number">{{ count($activeSessions) }}</p>
                    <p class="showcase-kpi-meta">Acces web detectes</p>
                </article>
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">Mot de passe</p>
                    <p class="showcase-kpi-number text-[1.45rem]">{{ $passwordExpired ? 'Expire' : 'Valide' }}</p>
                    <p class="showcase-kpi-meta">{{ $passwordExpired ? 'Renouvellement requis' : 'Conforme a la politique actuelle' }}</p>
                </article>
            </div>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <form method="POST" enctype="multipart/form-data" class="form-shell" action="{{ route('workspace.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Informations personnelles</h2>
                <p class="form-section-subtitle">Photo de profil et informations d identification du compte.</p>
                <div class="form-grid">
                    <div>
                        <label for="profile_photo">Photo de profil</label>
                        <div class="showcase-upload-zone">
                            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">Importer une nouvelle photo</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Formats acceptes: JPG, PNG, WEBP. Taille max 3 Mo.</p>
                            <input id="profile_photo" class="mt-4" name="profile_photo" type="file" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        @if ($user->profile_photo_url)
                            <label class="mt-3 !mb-0 inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
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
                <h2 class="form-section-title">Securite</h2>
                <p class="form-section-subtitle">Le changement de mot de passe exige le mot de passe actuel.</p>
                <div class="form-grid">
                    <div>
                        <label for="current_password">Mot de passe actuel</label>
                        <input id="current_password" name="current_password" type="password">
                    </div>
                    <div>
                        <label for="password">Nouveau mot de passe</label>
                        <input id="password" name="password" type="password">
                    </div>
                    <div>
                        <label for="password_confirmation">Confirmation</label>
                        <input id="password_confirmation" name="password_confirmation" type="password">
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-green rounded-2xl px-4 py-2.5" type="submit">Enregistrer</button>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('dashboard') }}">Retour dashboard</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Sessions actives</h2>
                <p class="showcase-panel-subtitle">Liste des acces web actuellement ouverts sur votre compte.</p>
            </div>
            <form method="POST" action="{{ route('workspace.profile.sessions.revoke_others') }}">
                @csrf
                <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">
                    Revoquer toutes les autres sessions
                </button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Statut</th>
                        <th class="px-3 py-2">Adresse IP</th>
                        <th class="px-3 py-2">Derniere activite</th>
                        <th class="px-3 py-2">Client</th>
                        <th class="px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activeSessions as $session)
                        <tr class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-3 py-2">
                                @if ($session['is_current'])
                                    <span class="rounded-full bg-[#eef6e1] px-2 py-1 text-xs font-semibold text-[#1c203d] dark:bg-[#8fc043]/15 dark:text-[#f8e932]">Session courante</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-200">Active</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-medium text-slate-800 dark:text-slate-100">{{ $session['ip_address'] }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                                {{ $session['last_activity']?->format('d/m/Y H:i:s') ?? 'N/A' }}
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ $session['user_agent'] }}</td>
                            <td class="px-3 py-2 text-right">
                                <form method="POST" action="{{ $session['is_current'] ? route('workspace.profile.sessions.revoke_current') : route('workspace.profile.sessions.revoke', $session['id']) }}">
                                    @csrf
                                    <button class="btn btn-red btn-sm" type="submit">
                                        {{ $session['is_current'] ? 'Fermer cette session' : 'Revoquer' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-slate-500 dark:text-slate-400">Aucune session active detectee.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
