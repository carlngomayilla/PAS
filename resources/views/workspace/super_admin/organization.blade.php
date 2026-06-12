@extends('layouts.workspace')

@section('title', 'Organisation et utilisateurs')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Organisation et utilisateurs</h1>
                <p class="mt-2 text-slate-600">Pilotage direct des directions, des services, des comptes, des sessions actives et des réinitialisations contrôlées.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.directions.index') }}">Référentiel directions</a>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.services.index') }}">Référentiel services</a>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Référentiel utilisateurs</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.index') }}">Retour super admin</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Directions actives</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['directions_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Services actifs</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['services_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Utilisateurs actifs</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['users_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Comptes hors scope</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['users_without_scope'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Sessions actives</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['sessions_active'] }}</p></article>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Filtrer les comptes</h2>
        <form method="GET" action="{{ route('workspace.super-admin.organization.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Nom, email ou matricule">
                </div>
                <div>
                    <label for="role">Rôle</label>
                    <select id="role" name="role">
                        <option value="">Tous</option>
                        @foreach ($roleOptions as $role)
                            <option value="{{ $role }}" @selected($filters['role'] === $role)>{{ $roleLabels[$role] ?? $role }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected($filters['direction_id'] === $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id">
                        <option value="">Tous</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected($filters['service_id'] === $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="is_active">État</label>
                    <select id="is_active" name="is_active">
                        <option value="">Tous</option>
                        <option value="1" @selected($filters['is_active'] === '1')>Actifs</option>
                        <option value="0" @selected($filters['is_active'] === '0')>Inactifs</option>
                    </select>
                </div>
                <div>
                    <label for="suspension_state">Suspension</label>
                    <select id="suspension_state" name="suspension_state">
                        <option value="">Toutes</option>
                        <option value="suspended" @selected($filters['suspension_state'] === 'suspended')>Suspendus</option>
                        <option value="not_suspended" @selected($filters['suspension_state'] === 'not_suspended')>Non suspendus</option>
                    </select>
                </div>
                <div>
                    <label for="auth_action">Type connexion</label>
                    <select id="auth_action" name="auth_action">
                        <option value="">Toutes</option>
                        <option value="login_success" @selected($filters['auth_action'] === 'login_success')>Connexions</option>
                        <option value="logout" @selected($filters['auth_action'] === 'logout')>Déconnexions</option>
                    </select>
                </div>
                <div>
                    <label for="auth_date_from">Connexions depuis</label>
                    <input id="auth_date_from" name="auth_date_from" type="date" value="{{ $filters['auth_date_from'] }}">
                </div>
                <div>
                    <label for="auth_date_to">Connexions jusqu au</label>
                    <input id="auth_date_to" name="auth_date_to" type="date" value="{{ $filters['auth_date_to'] }}">
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.organization.index') }}">Réinitialiser</a>
            </div>
        </form>
    </section>

    <section class="grid gap-4 xl:grid-cols-[1.2fr,0.8fr] mb-3.5">
        <article class="ui-card !mb-0">
            <h2>Import utilisateurs</h2>
            <p class="text-slate-600">CSV attendu : <code>name</code>, <code>email</code>, <code>role</code>, <code>base_role</code>, <code>custom_role_code</code>, <code>direction_code</code>, <code>service_code</code>, <code>agent_matricule</code>, <code>agent_fonction</code>, <code>agent_telephone</code>, <code>is_active</code>, <code>is_agent</code>, <code>suspended_until</code>, <code>suspension_reason</code>, <code>password</code>.</p>
            <form class="mt-4 flex flex-wrap items-end gap-3" method="POST" action="{{ route('workspace.super-admin.organization.users.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="min-w-[18rem] flex-1">
                    <label for="users_file">Fichier CSV</label>
                    <input id="users_file" name="users_file" type="file" accept=".csv,.txt" required>
                </div>
                <button class="btn btn-primary" type="submit">Importer</button>
            </form>
        </article>
        <article class="ui-card !mb-0">
            <h2>Actions de masse</h2>
            <p class="text-slate-600">Sélectionne des comptes dans le tableau puis applique un rôle, un scope ou une action de sécurité.</p>
            <div class="mt-4 grid gap-2 text-sm text-slate-600">
                <div>1. Coche les utilisateurs cibles</div>
                <div>2. Choisis l action de masse</div>
                <div>3. Complète le rôle ou le scope si nécessaire</div>
            </div>
        </article>
    </section>

    @php
        $editingDirection = $editingDirection ?? null;
        $editingService = $editingService ?? null;
        $editingUser = $editingUser ?? null;
    @endphp

    <section class="grid gap-4 xl:grid-cols-3 mb-3.5">
        <article class="ui-card !mb-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>{{ $editingDirection ? 'Modifier direction' : 'Nouvelle direction' }}</h2>
                    <p class="text-slate-600">Creation et mise a jour directe sans sortir du cockpit Super Admin.</p>
                </div>
                @if ($editingDirection)
                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index') }}">Annuler</a>
                @endif
            </div>
            <form class="mt-4 form-grid" method="POST" action="{{ $editingDirection ? route('workspace.super-admin.organization.directions.update', $editingDirection) : route('workspace.super-admin.organization.directions.store') }}">
                @csrf
                @if ($editingDirection) @method('PUT') @endif
                <div>
                    <label for="direction_code">Code</label>
                    <input id="direction_code" name="code" type="text" value="{{ old('code', $editingDirection?->code) }}" required>
                </div>
                <div>
                    <label for="direction_libelle">Libellé</label>
                    <input id="direction_libelle" name="libelle" type="text" value="{{ old('libelle', $editingDirection?->libelle) }}" required>
                </div>
                <div class="md:col-span-2 flex items-end gap-3">
                    <label class="checkbox-pill !mb-0">
                        <input name="actif" type="checkbox" value="1" @checked(old('actif', $editingDirection?->actif ?? true))>
                        Active
                    </label>
                    <button class="btn btn-primary" type="submit">{{ $editingDirection ? 'Mettre à jour' : 'Créer direction' }}</button>
                </div>
            </form>
        </article>

        <article class="ui-card !mb-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>{{ $editingService ? 'Modifier service' : 'Nouveau service' }}</h2>
                    <p class="text-slate-600">Rattachement direction et activation sans repasser par le referentiel classique.</p>
                </div>
                @if ($editingService)
                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index') }}">Annuler</a>
                @endif
            </div>
            <form class="mt-4 form-grid" method="POST" action="{{ $editingService ? route('workspace.super-admin.organization.services.update', $editingService) : route('workspace.super-admin.organization.services.store') }}">
                @csrf
                @if ($editingService) @method('PUT') @endif
                <div>
                    <label for="service_direction_id">Direction</label>
                    <select id="service_direction_id" name="direction_id" required>
                        <option value="">Choisir</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) old('direction_id', $editingService?->direction_id) === (int) $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="service_code">Code</label>
                    <input id="service_code" name="code" type="text" value="{{ old('code', $editingService?->code) }}" required>
                </div>
                <div class="md:col-span-2">
                    <label for="service_libelle">Libellé</label>
                    <input id="service_libelle" name="libelle" type="text" value="{{ old('libelle', $editingService?->libelle) }}" required>
                </div>
                <div class="md:col-span-2 flex items-end gap-3">
                    <label class="checkbox-pill !mb-0">
                        <input name="actif" type="checkbox" value="1" @checked(old('actif', $editingService?->actif ?? true))>
                        Actif
                    </label>
                    <button class="btn btn-primary" type="submit">{{ $editingService ? 'Mettre à jour' : 'Créer service' }}</button>
                </div>
            </form>
        </article>

        <article class="ui-card !mb-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>{{ $editingUser ? 'Modifier utilisateur' : 'Nouvel utilisateur' }}</h2>
                    <p class="text-slate-600">Gestion directe des comptes avec rôle, périmètre et mot de passe pilote.</p>
                </div>
                @if ($editingUser)
                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index') }}">Annuler</a>
                @endif
            </div>
            <form class="mt-4 form-grid" method="POST" action="{{ $editingUser ? route('workspace.super-admin.organization.users.update', $editingUser) : route('workspace.super-admin.organization.users.store') }}">
                @csrf
                @if ($editingUser) @method('PUT') @endif
                <div>
                    <label for="managed_name">Nom</label>
                    <input id="managed_name" name="name" type="text" value="{{ old('name', $editingUser?->name) }}" required>
                </div>
                <div>
                    <label for="managed_email">Email</label>
                    <input id="managed_email" name="email" type="email" value="{{ old('email', $editingUser?->email) }}" required>
                </div>
                <div>
                    <label for="managed_role">Rôle</label>
                    <select id="managed_role" name="role" required>
                        @foreach ($roleOptions as $role)
                            <option value="{{ $role }}" @selected(old('role', $editingUser?->effectiveRoleCode()) === $role)>{{ $roleLabels[$role] ?? $role }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="managed_direction_id">Direction</label>
                    <select id="managed_direction_id" name="direction_id">
                        <option value="">Aucune</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) old('direction_id', $editingUser?->direction_id) === (int) $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="managed_service_id">Service</label>
                    <select id="managed_service_id" name="service_id">
                        <option value="">Aucun</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected((int) old('service_id', $editingUser?->service_id) === (int) $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="managed_matricule">Matricule</label>
                    <input id="managed_matricule" name="agent_matricule" type="text" value="{{ old('agent_matricule', $editingUser?->agent_matricule) }}">
                </div>
                <div>
                    <label for="managed_fonction">Fonction</label>
                    <input id="managed_fonction" name="agent_fonction" type="text" value="{{ old('agent_fonction', $editingUser?->agent_fonction) }}">
                </div>
                <div>
                    <label for="managed_telephone">Téléphone</label>
                    <input id="managed_telephone" name="agent_telephone" type="text" value="{{ old('agent_telephone', $editingUser?->agent_telephone) }}">
                </div>
                <div>
                    <label for="managed_password">{{ $editingUser ? 'Nouveau mot de passe' : 'Mot de passe (optionnel)' }}</label>
                    <div class="relative">
                        <input id="managed_password" name="password" type="password" class="pr-16">
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="managed_password">
                            Voir
                        </button>
                    </div>
                    @unless($editingUser)
                        <p class="mt-1 text-xs text-slate-500">Laissez vide pour appliquer le mot de passe par défaut : <code>Anbg@2026!Pas</code></p>
                    @endunless
                </div>
                <div>
                    <label for="managed_password_confirmation">Confirmation</label>
                    <div class="relative">
                        <input id="managed_password_confirmation" name="password_confirmation" type="password" class="pr-16">
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="managed_password_confirmation">
                            Voir
                        </button>
                    </div>
                </div>
                <div>
                    <label for="managed_suspended_until">Suspendu jusqu au</label>
                    <input id="managed_suspended_until" name="suspended_until" type="date" value="{{ old('suspended_until', optional($editingUser?->suspended_until)->toDateString()) }}">
                </div>
                <div class="md:col-span-2">
                    <label for="managed_suspension_reason">Motif de suspension</label>
                    <input id="managed_suspension_reason" name="suspension_reason" type="text" value="{{ old('suspension_reason', $editingUser?->suspension_reason) }}" placeholder="Motif interne optionnel">
                </div>
                @if ($editingUser)
                    <div>
                        <label for="managed_transfer_to_user_id">Repreneur taches ouvertes</label>
                        <select id="managed_transfer_to_user_id" name="transfer_to_user_id">
                            <option value="">Auto si meme perimetre</option>
                            @foreach ($transferUserOptions as $candidate)
                                @continue((int) $candidate->id === (int) $editingUser->id)
                                <option value="{{ $candidate->id }}" @selected((int) old('transfer_to_user_id') === (int) $candidate->id)>
                                    {{ $candidate->name }} - {{ $candidate->service?->code ?? $candidate->direction?->code ?? 'global' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="managed_motif">Motif gouvernance</label>
                        <input id="managed_motif" name="motif" type="text" value="{{ old('motif') }}" placeholder="Obligatoire si role, direction, service ou etat change">
                    </div>
                @endif
                <div class="md:col-span-2 flex flex-wrap items-end gap-3">
                    <label class="checkbox-pill !mb-0">
                        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $editingUser?->is_active ?? true))>
                        Actif
                    </label>
                    <label class="checkbox-pill !mb-0">
                        <input name="is_agent" type="checkbox" value="1" @checked(old('is_agent', $editingUser?->is_agent ?? false))>
                        Marquer comme agent
                    </label>
                    <button class="btn btn-primary" type="submit">{{ $editingUser ? 'Mettre à jour' : 'Créer utilisateur' }}</button>
                </div>
            </form>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Directions</h2>
                <p class="text-slate-600">Activation rapide et edition directe des directions.</p>
            </div>
            <span class="showcase-chip">{{ count($directionRows) }} lignes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead><tr><th>Direction</th><th>État</th><th>Services</th><th>Utilisateurs</th><th>Actions</th></tr></thead>
                <tbody>
                    @foreach ($directionRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-900">{{ $row->code }} - {{ $row->libelle }}</td>
                            <td><span class="anbg-badge {{ $row->actif ? 'anbg-badge-success' : 'anbg-badge-danger' }} px-3">{{ $row->actif ? 'Active' : 'Inactive' }}</span></td>
                            <td>{{ $row->services_count }}</td>
                            <td>{{ $row->users_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index', ['edit_direction' => $row->id]) }}">Editer</a>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.directions.toggle', $row) }}">
                                        @csrf
                                        <button class="btn {{ $row->actif ? 'btn-danger' : 'btn-success' }} btn-sm rounded-xl" type="submit">{{ $row->actif ? 'Desactiver' : 'Activer' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Services</h2>
                <p class="text-slate-600">Lecture rapide, edition et bascule d activation des services.</p>
            </div>
            <span class="showcase-chip">{{ count($serviceRows) }} lignes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead><tr><th>Service</th><th>État</th><th>Utilisateurs</th><th>PTA</th><th>Actions</th></tr></thead>
                <tbody>
                    @foreach ($serviceRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-900">{{ $row->direction?->code }} / {{ $row->code }} - {{ $row->libelle }}</td>
                            <td><span class="anbg-badge {{ $row->actif ? 'anbg-badge-success' : 'anbg-badge-danger' }} px-3">{{ $row->actif ? 'Actif' : 'Inactif' }}</span></td>
                            <td>{{ $row->users_count }}</td>
                            <td>{{ $row->ptas_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index', ['edit_service' => $row->id]) }}">Editer</a>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.services.toggle', $row) }}">
                                        @csrf
                                        <button class="btn {{ $row->actif ? 'btn-danger' : 'btn-success' }} btn-sm rounded-xl" type="submit">{{ $row->actif ? 'Desactiver' : 'Activer' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Utilisateurs avances</h2>
                <p class="text-slate-600">CRUD direct des comptes, purge des sessions et mot de passe temporaire sans sortir du cockpit Super Admin.</p>
            </div>
            <span class="showcase-chip">{{ $userRows->total() }} comptes</span>
        </div>
        <form method="POST" action="{{ route('workspace.super-admin.organization.users.bulk') }}" class="mt-4">
            @csrf
            <div class="form-grid-compact mb-3">
                <div>
                    <label for="bulk_action">Action de masse</label>
                    <select id="bulk_action" name="bulk_action" required>
                        <option value="">Choisir</option>
                        @foreach ($bulkActionOptions as $actionCode => $actionLabel)
                            <option value="{{ $actionCode }}">{{ $actionLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulk_role">Rôle cible</label>
                    <select id="bulk_role" name="bulk_role">
                        <option value="">Aucun</option>
                        @foreach ($roleOptions as $role)
                            <option value="{{ $role }}">{{ $roleLabels[$role] ?? $role }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulk_direction_id">Direction cible</label>
                    <select id="bulk_direction_id" name="bulk_direction_id">
                        <option value="">Aucune</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}">{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulk_service_id">Service cible</label>
                    <select id="bulk_service_id" name="bulk_service_id">
                        <option value="">Aucun</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}">{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulk_transfer_to_user_id">Repreneur taches</label>
                    <select id="bulk_transfer_to_user_id" name="bulk_transfer_to_user_id">
                        <option value="">Automatique</option>
                        @foreach ($transferUserOptions as $candidate)
                            @if ($candidate->is_active)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }} - {{ $candidate->service?->code ?? $candidate->direction?->code ?? 'global' }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulk_suspended_until">Suspendre jusqu au</label>
                    <input id="bulk_suspended_until" name="bulk_suspended_until" type="date">
                </div>
                <div>
                    <label for="bulk_suspension_reason">Motif suspension</label>
                    <input id="bulk_suspension_reason" name="bulk_suspension_reason" type="text" placeholder="Facultatif">
                </div>
                <div class="md:col-span-2">
                    <label for="bulk_motif">Motif gouvernance</label>
                    <input id="bulk_motif" name="bulk_motif" type="text" placeholder="Obligatoire pour les actions sensibles, sinon motif automatique">
                </div>
            </div>
            <div class="mb-3 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer aux lignes cochées</button>
            </div>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead><tr><th><input type="checkbox" data-check-all-users></th><th>Utilisateur</th><th>Rôle</th><th>Portée</th><th>État</th><th>Suspension</th><th>Sessions</th><th>Dernière activité</th><th>Opérations</th></tr></thead>
                <tbody>
                    @forelse ($userRows as $row)
                        <tr>
                            <td><input type="checkbox" name="user_ids[]" value="{{ $row->id }}" data-bulk-user></td>
                            <td>
                                <div class="font-semibold text-slate-900">{{ $row->name }}</div>
                                <div class="text-xs text-slate-500">{{ $row->email }}</div>
                                @if ($row->agent_matricule)
                                    <div class="text-xs text-slate-500">Matricule : {{ $row->agent_matricule }}</div>
                                @endif
                            </td>
                            <td>{{ $row->roleLabel() }}</td>
                            <td>
                                <div>{{ $row->profileScopeLabel() }}</div>
                                <div class="text-xs text-slate-500">{{ $row->direction?->code ?? '-' }} / {{ $row->service?->code ?? '-' }}</div>
                            </td>
                            <td><span class="anbg-badge {{ $row->is_active ? 'anbg-badge-success' : 'anbg-badge-danger' }} px-3">{{ $row->is_active ? 'Actif' : 'Inactif' }}</span></td>
                            <td>
                                @if ($row->isSuspended())
                                    <div><span class="anbg-badge anbg-badge-warning px-3">Suspendu</span></div>
                                    <div class="mt-1 text-xs text-slate-500">Jusqu au {{ $row->suspended_until?->format('Y-m-d') }}</div>
                                    @if ($row->suspension_reason)
                                        <div class="mt-1 text-xs text-slate-500">{{ $row->suspension_reason }}</div>
                                    @endif
                                @else
                                    <span class="anbg-badge anbg-badge-neutral px-3">Aucune</span>
                                @endif
                            </td>
                            <td>{{ (int) ($row->sessions_total ?? 0) }}</td>
                            <td>{{ $row->last_session_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index', ['edit_user' => $row->id]) }}">Editer</a>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.users.toggle', $row) }}">
                                        @csrf
                                        <input type="hidden" name="motif" value="{{ $row->is_active ? 'Desactivation via bascule Super Admin' : 'Reactivation via bascule Super Admin' }}">
                                        <button class="btn {{ $row->is_active ? 'btn-danger' : 'btn-success' }} btn-sm rounded-xl" type="submit">{{ $row->is_active ? 'Desactiver' : 'Activer' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.users.revoke-sessions', $row) }}">
                                        @csrf
                                        <button class="btn btn-secondary btn-sm rounded-xl" type="submit">Couper sessions</button>
                                    </form>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.users.reset-password', $row) }}">
                                        @csrf
                                        <button class="btn btn-warning btn-sm rounded-xl" type="submit">Reset mot de passe</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-ui.empty-state
                                    title="Aucun compte trouvé"
                                    message="Aucun utilisateur ne correspond aux filtres courants."
                                    icon="users"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $userRows->links() }}</div>
        </form>
    </section>

    <section class="ui-card">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Historique récent des connexions</h2>
                <p class="text-slate-600">Journal exploitable des connexions et déconnexions récentes des comptes actuellement filtres.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="showcase-chip">{{ $loginHistory->count() }} événements</span>
            </div>
        </div>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>IP</th><th>Agent</th></tr></thead>
                <tbody>
                    @forelse ($loginHistory as $row)
                        <tr>
                            <td>{{ $row->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>
                                <div class="font-semibold text-slate-900">{{ $row->user?->name ?? 'Utilisateur supprime' }}</div>
                                <div class="text-xs text-slate-500">{{ $row->user?->email ?? '-' }}</div>
                            </td>
                            <td>
                                <span class="anbg-badge {{ $row->action === 'login_success' ? 'anbg-badge-success' : 'anbg-badge-neutral' }} px-3">
                                    {{ $row->action === 'login_success' ? 'Connexion' : 'Déconnexion' }}
                                </span>
                            </td>
                            <td>{{ $row->adresse_ip ?: '-' }}</td>
                            <td class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) $row->user_agent, 70) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state
                                    title="Aucun événement récent"
                                    message="Aucune connexion ou déconnexion ne correspond aux filtres courants."
                                    icon="clock"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2 mt-3.5">
        <article class="ui-card !mb-0">
            <h2>Simulation de fusion de services</h2>
            <form method="GET" action="{{ route('workspace.super-admin.organization.index') }}" class="mt-4 form-grid-compact">
                <div>
                    <label for="merge_source_service_id">Service source</label>
                    <select id="merge_source_service_id" name="merge_source_service_id">
                        <option value="">Choisir</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" @selected((int) request('merge_source_service_id') === (int) $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="merge_target_service_id">Service cible</label>
                    <select id="merge_target_service_id" name="merge_target_service_id">
                        <option value="">Choisir</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" @selected((int) request('merge_target_service_id') === (int) $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button class="btn btn-primary" type="submit">Simuler</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.super-admin.organization.index', request()->except(['merge_source_service_id', 'merge_target_service_id'])) }}">Effacer</a>
                </div>
            </form>
            @if (is_array($mergeSimulation))
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">Utilisateurs impactés</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['users_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">Actions impactées</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['actions_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">PTA impactés</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['ptas_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">Justificatifs impactés</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['justificatifs_total'] ?? 0 }}</p></article>
                </div>
                @if (($mergeSimulation['warnings'] ?? []) !== [])
                    <div class="mt-3 space-y-2">
                        @foreach ($mergeSimulation['warnings'] as $warning)
                            <div class="rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $warning }}</div>
                        @endforeach
                    </div>
                @endif
            @endif
        </article>

        <article class="ui-card !mb-0">
            <h2>Simulation de transfert de service</h2>
            <form method="GET" action="{{ route('workspace.super-admin.organization.index') }}" class="mt-4 form-grid-compact">
                <div>
                    <label for="transfer_service_id">Service à déplacer</label>
                    <select id="transfer_service_id" name="transfer_service_id">
                        <option value="">Choisir</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" @selected((int) request('transfer_service_id') === (int) $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer_direction_id">Direction cible</label>
                    <select id="transfer_direction_id" name="transfer_direction_id">
                        <option value="">Choisir</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) request('transfer_direction_id') === (int) $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button class="btn btn-primary" type="submit">Simuler</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.super-admin.organization.index', request()->except(['transfer_service_id', 'transfer_direction_id'])) }}">Effacer</a>
                </div>
            </form>
            @if (is_array($transferSimulation))
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">Utilisateurs impactés</p><p class="mt-2 text-2xl font-bold">{{ $transferSimulation['impact']['users_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">Actions impactées</p><p class="mt-2 text-2xl font-bold">{{ $transferSimulation['impact']['actions_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">PTA impactés</p><p class="mt-2 text-2xl font-bold">{{ $transferSimulation['impact']['ptas_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4"><p class="text-sm text-slate-500">Direction cible</p><p class="mt-2 text-lg font-semibold">{{ $transferSimulation['target_direction'] ?? '-' }}</p></article>
                </div>
                @if (($transferSimulation['warnings'] ?? []) !== [])
                    <div class="mt-3 space-y-2">
                        @foreach ($transferSimulation['warnings'] as $warning)
                            <div class="rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $warning }}</div>
                        @endforeach
                    </div>
                @endif
            @endif
        </article>
    </section>

    <section id="deletion-requests" class="ui-card mt-3.5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Demandes de suppression</h2>
                <p class="text-slate-600">Demandes motivees avec analyse d impact avant decision Super Admin.</p>
            </div>
            <span class="showcase-chip">{{ count($deletionRequests ?? []) }} ouvertes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead><tr><th>Date</th><th>Demandeur</th><th>Cible</th><th>Impact</th><th>Decision</th></tr></thead>
                <tbody>
                    @forelse (($deletionRequests ?? []) as $row)
                        @php
                            $impact = is_array($row->impact_summary ?? null) ? $row->impact_summary : [];
                            $openAssignments = (int) data_get($impact, 'open_assignments.total', 0);
                            $totalImpact = (int) data_get($impact, 'total', 0);
                            $blockingImpact = (int) data_get($impact, 'blocking_total', $totalImpact);
                        @endphp
                        <tr>
                            <td>{{ $row->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>
                                <p class="font-medium text-slate-900">{{ $row->requester?->name ?? 'Systeme' }}</p>
                                <p class="text-xs text-slate-600">{{ $row->requester?->email ?? '-' }}</p>
                            </td>
                            <td>
                                <p class="font-medium text-slate-900">{{ $row->entity_label ?? ('Element #'.$row->entity_id) }}</p>
                                <p class="text-xs text-slate-600">{{ $row->reason }}</p>
                            </td>
                            <td>
                                <span class="anbg-badge {{ $blockingImpact > 0 ? 'anbg-badge-danger' : 'anbg-badge-success' }} px-3">
                                    {{ $blockingImpact }} bloquant(s)
                                </span>
                                <p class="mt-1 text-xs text-slate-600">{{ $totalImpact }} lien(s) analyses</p>
                                <p class="mt-1 text-xs text-slate-600">{{ $openAssignments }} tache(s) ouverte(s)</p>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('workspace.super-admin.organization.deletion-requests.decision', $row) }}" class="grid min-w-[22rem] gap-2">
                                    @csrf
                                    <select name="decision" required>
                                        <option value="request_complement">Demander complement</option>
                                        <option value="reject">Refuser</option>
                                        <option value="disable">Desactiver</option>
                                        <option value="delete">Supprimer si impact metier nul</option>
                                        <option value="archive">Archiver la demande</option>
                                        <option value="correct">Marquer corrige</option>
                                    </select>
                                    <select name="transfer_to_user_id">
                                        <option value="">Repreneur si desactivation</option>
                                        @foreach ($transferUserOptions as $candidate)
                                            <option value="{{ $candidate->id }}">{{ $candidate->name }} - {{ $candidate->service?->code ?? $candidate->direction?->code ?? 'global' }}</option>
                                        @endforeach
                                    </select>
                                    <input name="reviewer_note" type="text" placeholder="Motif de decision" required>
                                    <button class="btn btn-primary" type="submit">Enregistrer</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state
                                    title="Aucune demande ouverte"
                                    message="Les demandes de suppression motivees apparaitront ici."
                                    icon="shield"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-card mt-3.5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Historique d organisation</h2>
                <p class="text-slate-600">Dernières opérations sensibles sur la structure, les comptes et les rôles pilotes.</p>
            </div>
            <span class="showcase-chip">{{ count($orgHistory ?? []) }} opérations</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Entité</th></tr></thead>
                <tbody>
                    @forelse (($orgHistory ?? []) as $row)
                        <tr>
                            <td>{{ $row->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $row->user?->email ?? 'Système' }}</td>
                            <td>{{ $row->action }}</td>
                            <td>{{ $row->entite_type ?? '-' }} #{{ $row->entite_id ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <x-ui.empty-state
                                    title="Aucune opération récente"
                                    message="Les opérations sensibles apparaîtront ici après les prochains changements."
                                    icon="clock"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

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

        document.addEventListener('DOMContentLoaded', function () {
            var toggle = document.querySelector('[data-check-all-users]');
            if (toggle) {
                toggle.addEventListener('change', function () {
                    document.querySelectorAll('[data-bulk-user]').forEach(function (checkbox) {
                        checkbox.checked = toggle.checked;
                    });
                });
            }

            function bindDependentServices(directionId, serviceId) {
                var directionInput = document.getElementById(directionId);
                var serviceInput = document.getElementById(serviceId);

                if (!directionInput || !serviceInput) {
                    return;
                }

                function syncServices() {
                    var selectedDirection = String(directionInput.value || '');
                    var selectedService = String(serviceInput.value || '');
                    var selectedStillVisible = false;

                    Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                        if (index === 0) {
                            option.hidden = false;
                            option.disabled = false;
                            return;
                        }

                        var visible = selectedDirection === '' || String(option.getAttribute('data-direction-id') || '') === selectedDirection;
                        option.hidden = !visible;
                        option.disabled = !visible;

                        if (visible && option.value === selectedService) {
                            selectedStillVisible = true;
                        }
                    });

                    if (selectedService && !selectedStillVisible) {
                        serviceInput.value = '';
                    }
                }

                directionInput.addEventListener('change', syncServices);
                syncServices();
            }

            bindDependentServices('direction_id', 'service_id');
            bindDependentServices('managed_direction_id', 'managed_service_id');
            bindDependentServices('bulk_direction_id', 'bulk_service_id');
        });
    </script>
@endsection
