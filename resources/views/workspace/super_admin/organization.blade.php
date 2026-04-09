@extends('layouts.workspace')

@section('title', 'Organisation et utilisateurs')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Organisation et utilisateurs</h1>
                <p class="mt-2 text-slate-600">Pilotage direct des directions, des services, des comptes, des sessions actives et des reinitialisations controlees.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.directions.index') }}">Referentiel directions</a>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.services.index') }}">Referentiel services</a>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Referentiel utilisateurs</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.index') }}">Retour super admin</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Directions actives</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['directions_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Services actifs</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['services_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Utilisateurs actifs</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['users_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Utilisateurs inactifs</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['users_inactive'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Directions inactives</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['directions_inactive'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Services inactifs</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['services_inactive'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Comptes hors scope</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['users_without_scope'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Sessions actives</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['sessions_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Comptes suspendus</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['users_suspended'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Connexions journalisees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['login_events_total'] }}</p></article>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtrer les comptes</h2>
        <form method="GET" action="{{ route('workspace.super-admin.organization.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Nom, email ou matricule">
                </div>
                <div>
                    <label for="role">Role</label>
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
                            <option value="{{ $service->id }}" @selected($filters['service_id'] === $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="is_active">Etat</label>
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
                        <option value="logout" @selected($filters['auth_action'] === 'logout')>Deconnexions</option>
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
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.organization.index') }}">Reinitialiser</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.organization.users.export', request()->query()) }}">Exporter CSV</a>
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
            <p class="text-slate-600">Selectionne des comptes dans le tableau puis applique un role, un scope ou une action de securite.</p>
            <div class="mt-4 grid gap-2 text-sm text-slate-600">
                <div>1. Coche les utilisateurs cibles</div>
                <div>2. Choisis l action de masse</div>
                <div>3. Complete le role ou le scope si necessaire</div>
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
                    <label for="direction_libelle">Libelle</label>
                    <input id="direction_libelle" name="libelle" type="text" value="{{ old('libelle', $editingDirection?->libelle) }}" required>
                </div>
                <div class="md:col-span-2 flex items-end gap-3">
                    <label class="checkbox-pill !mb-0">
                        <input name="actif" type="checkbox" value="1" @checked(old('actif', $editingDirection?->actif ?? true))>
                        Active
                    </label>
                    <button class="btn btn-primary" type="submit">{{ $editingDirection ? 'Mettre a jour' : 'Creer direction' }}</button>
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
                    <label for="service_libelle">Libelle</label>
                    <input id="service_libelle" name="libelle" type="text" value="{{ old('libelle', $editingService?->libelle) }}" required>
                </div>
                <div class="md:col-span-2 flex items-end gap-3">
                    <label class="checkbox-pill !mb-0">
                        <input name="actif" type="checkbox" value="1" @checked(old('actif', $editingService?->actif ?? true))>
                        Actif
                    </label>
                    <button class="btn btn-primary" type="submit">{{ $editingService ? 'Mettre a jour' : 'Creer service' }}</button>
                </div>
            </form>
        </article>

        <article class="ui-card !mb-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>{{ $editingUser ? 'Modifier utilisateur' : 'Nouvel utilisateur' }}</h2>
                    <p class="text-slate-600">CRUD direct des comptes avec role, perimetre et mot de passe pilote.</p>
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
                    <label for="managed_role">Role</label>
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
                            <option value="{{ $service->id }}" @selected((int) old('service_id', $editingUser?->service_id) === (int) $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
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
                    <label for="managed_telephone">Telephone</label>
                    <input id="managed_telephone" name="agent_telephone" type="text" value="{{ old('agent_telephone', $editingUser?->agent_telephone) }}">
                </div>
                <div>
                    <label for="managed_password">{{ $editingUser ? 'Nouveau mot de passe' : 'Mot de passe' }}</label>
                    <input id="managed_password" name="password" type="password" {{ $editingUser ? '' : 'required' }}>
                </div>
                <div>
                    <label for="managed_password_confirmation">Confirmation</label>
                    <input id="managed_password_confirmation" name="password_confirmation" type="password" {{ $editingUser ? '' : 'required' }}>
                </div>
                <div>
                    <label for="managed_suspended_until">Suspendu jusqu au</label>
                    <input id="managed_suspended_until" name="suspended_until" type="date" value="{{ old('suspended_until', optional($editingUser?->suspended_until)->toDateString()) }}">
                </div>
                <div class="md:col-span-2">
                    <label for="managed_suspension_reason">Motif de suspension</label>
                    <input id="managed_suspension_reason" name="suspension_reason" type="text" value="{{ old('suspension_reason', $editingUser?->suspension_reason) }}" placeholder="Motif interne optionnel">
                </div>
                <div class="md:col-span-2 flex flex-wrap items-end gap-3">
                    <label class="checkbox-pill !mb-0">
                        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $editingUser?->is_active ?? true))>
                        Actif
                    </label>
                    <label class="checkbox-pill !mb-0">
                        <input name="is_agent" type="checkbox" value="1" @checked(old('is_agent', $editingUser?->is_agent ?? false))>
                        Marquer comme agent
                    </label>
                    <button class="btn btn-primary" type="submit">{{ $editingUser ? 'Mettre a jour' : 'Creer utilisateur' }}</button>
                </div>
            </form>
        </article>
    </section>

    <section class="ui-card mb-3.5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Directions</h2>
                <p class="text-slate-600">Activation rapide et edition directe des directions.</p>
            </div>
            <span class="showcase-chip">{{ count($directionRows) }} lignes</span>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Direction</th><th>Etat</th><th>Services</th><th>Utilisateurs</th><th>Actions</th></tr></thead>
                <tbody>
                    @foreach ($directionRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->code }} - {{ $row->libelle }}</td>
                            <td><span class="anbg-badge {{ $row->actif ? 'anbg-badge-success' : 'anbg-badge-danger' }} px-3">{{ $row->actif ? 'Active' : 'Inactive' }}</span></td>
                            <td>{{ $row->services_count }}</td>
                            <td>{{ $row->users_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index', ['edit_direction' => $row->id]) }}">Editer</a>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.directions.toggle', $row) }}">
                                        @csrf
                                        <button class="btn {{ $row->actif ? 'btn-red' : 'btn-green' }} btn-sm rounded-xl" type="submit">{{ $row->actif ? 'Desactiver' : 'Activer' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Services</h2>
                <p class="text-slate-600">Lecture rapide, edition et bascule d activation des services.</p>
            </div>
            <span class="showcase-chip">{{ count($serviceRows) }} lignes</span>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Service</th><th>Etat</th><th>Utilisateurs</th><th>PTA</th><th>Actions</th></tr></thead>
                <tbody>
                    @foreach ($serviceRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->direction?->code }} / {{ $row->code }} - {{ $row->libelle }}</td>
                            <td><span class="anbg-badge {{ $row->actif ? 'anbg-badge-success' : 'anbg-badge-danger' }} px-3">{{ $row->actif ? 'Actif' : 'Inactif' }}</span></td>
                            <td>{{ $row->users_count }}</td>
                            <td>{{ $row->ptas_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary btn-sm rounded-xl" href="{{ route('workspace.super-admin.organization.index', ['edit_service' => $row->id]) }}">Editer</a>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.services.toggle', $row) }}">
                                        @csrf
                                        <button class="btn {{ $row->actif ? 'btn-red' : 'btn-green' }} btn-sm rounded-xl" type="submit">{{ $row->actif ? 'Desactiver' : 'Activer' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-card mb-3.5">
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
                    <label for="bulk_role">Role cible</label>
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
                            <option value="{{ $service->id }}">{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
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
            </div>
            <div class="mb-3 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer aux lignes cochees</button>
            </div>
        <div class="mt-4 overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th><input type="checkbox" data-check-all-users></th><th>Utilisateur</th><th>Role</th><th>Portee</th><th>Etat</th><th>Suspension</th><th>Sessions</th><th>Derniere activite</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse ($userRows as $row)
                        <tr>
                            <td><input type="checkbox" name="user_ids[]" value="{{ $row->id }}" data-bulk-user></td>
                            <td>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->name }}</div>
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
                                        <button class="btn {{ $row->is_active ? 'btn-red' : 'btn-green' }} btn-sm rounded-xl" type="submit">{{ $row->is_active ? 'Desactiver' : 'Activer' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.users.revoke-sessions', $row) }}">
                                        @csrf
                                        <button class="btn btn-blue btn-sm rounded-xl" type="submit">Couper sessions</button>
                                    </form>
                                    <form method="POST" action="{{ route('workspace.super-admin.organization.users.reset-password', $row) }}">
                                        @csrf
                                        <button class="btn btn-amber btn-sm rounded-xl" type="submit">Reset mot de passe</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-slate-500">Aucun compte sur ce filtre.</td></tr>
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
                <h2>Historique recent des connexions</h2>
                <p class="text-slate-600">Journal exploitable des connexions et deconnexions recentes des comptes actuellement filtres.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="showcase-chip">{{ $loginHistory->count() }} evenements</span>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.organization.login-history.export', request()->query()) }}">Exporter CSV</a>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>IP</th><th>Agent</th></tr></thead>
                <tbody>
                    @forelse ($loginHistory as $row)
                        <tr>
                            <td>{{ $row->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->user?->name ?? 'Utilisateur supprime' }}</div>
                                <div class="text-xs text-slate-500">{{ $row->user?->email ?? '-' }}</div>
                            </td>
                            <td>
                                <span class="anbg-badge {{ $row->action === 'login_success' ? 'anbg-badge-success' : 'anbg-badge-neutral' }} px-3">
                                    {{ $row->action === 'login_success' ? 'Connexion' : 'Deconnexion' }}
                                </span>
                            </td>
                            <td>{{ $row->adresse_ip ?: '-' }}</td>
                            <td class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) $row->user_agent, 70) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-slate-500">Aucun evenement de connexion recent sur le filtre courant.</td></tr>
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
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">Utilisateurs impactes</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['users_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">Actions impactees</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['actions_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">PTA impactes</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['ptas_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">Justificatifs impactes</p><p class="mt-2 text-2xl font-bold">{{ $mergeSimulation['impact']['justificatifs_total'] ?? 0 }}</p></article>
                </div>
                @if (($mergeSimulation['warnings'] ?? []) !== [])
                    <div class="mt-3 space-y-2">
                        @foreach ($mergeSimulation['warnings'] as $warning)
                            <div class="rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">{{ $warning }}</div>
                        @endforeach
                    </div>
                @endif
            @endif
        </article>

        <article class="ui-card !mb-0">
            <h2>Simulation de transfert de service</h2>
            <form method="GET" action="{{ route('workspace.super-admin.organization.index') }}" class="mt-4 form-grid-compact">
                <div>
                    <label for="transfer_service_id">Service a deplacer</label>
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
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">Utilisateurs impactes</p><p class="mt-2 text-2xl font-bold">{{ $transferSimulation['impact']['users_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">Actions impactees</p><p class="mt-2 text-2xl font-bold">{{ $transferSimulation['impact']['actions_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">PTA impactes</p><p class="mt-2 text-2xl font-bold">{{ $transferSimulation['impact']['ptas_total'] ?? 0 }}</p></article>
                    <article class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80"><p class="text-sm text-slate-500">Direction cible</p><p class="mt-2 text-lg font-semibold">{{ $transferSimulation['target_direction'] ?? '-' }}</p></article>
                </div>
                @if (($transferSimulation['warnings'] ?? []) !== [])
                    <div class="mt-3 space-y-2">
                        @foreach ($transferSimulation['warnings'] as $warning)
                            <div class="rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">{{ $warning }}</div>
                        @endforeach
                    </div>
                @endif
            @endif
        </article>
    </section>

    <section class="ui-card mt-3.5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Historique d organisation</h2>
                <p class="text-slate-600">Dernieres operations sensibles sur la structure, les comptes et les roles pilotes.</p>
            </div>
            <span class="showcase-chip">{{ count($orgHistory ?? []) }} operations</span>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Entite</th></tr></thead>
                <tbody>
                    @forelse (($orgHistory ?? []) as $row)
                        <tr>
                            <td>{{ $row->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $row->user?->email ?? 'Systeme' }}</td>
                            <td>{{ $row->action }}</td>
                            <td>{{ $row->entite_type ?? '-' }} #{{ $row->entite_id ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-slate-500">Aucune operation recente.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var toggle = document.querySelector('[data-check-all-users]');
            if (!toggle) {
                return;
            }

            toggle.addEventListener('change', function () {
                document.querySelectorAll('[data-bulk-user]').forEach(function (checkbox) {
                    checkbox.checked = toggle.checked;
                });
            });
        });
    </script>
@endsection

