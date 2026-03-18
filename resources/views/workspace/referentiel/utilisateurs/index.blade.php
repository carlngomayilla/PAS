@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Referentiel - Utilisateurs</h1>
        <p class="text-slate-600">Gestion des comptes, profils et affectations direction/service.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="btn btn-green" href="{{ route('workspace.referentiel.utilisateurs.create') }}">Nouvel utilisateur</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Navigation referentiel</h2>
        <div class="flex flex-wrap gap-1.5">
            <a class="btn btn-blue" href="{{ route('workspace.referentiel.directions.index') }}">Directions</a>
            <a class="btn btn-blue" href="{{ route('workspace.referentiel.services.index') }}">Services</a>
            <a class="btn btn-primary" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Utilisateurs</a>
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.referentiel.utilisateurs.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Nom ou email">
                </div>
                <div>
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="">Tous</option>
                        @foreach ($roleOptions as $role)
                            <option value="{{ $role }}" @selected($filters['role'] === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected($filters['direction_id'] === $direction->id)>
                                {{ $direction->code }} - {{ $direction->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id">
                        <option value="">Tous</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" @selected($filters['service_id'] === $service->id)>
                                {{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des utilisateurs</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Profil</th>
                        <th>Role</th>
                        <th>Portee</th>
                        <th>Agent</th>
                        <th>Direction</th>
                        <th>Service</th>
                        @if ($canWrite)
                            <th>Operations</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>
                                <div class="flex min-w-[250px] items-center gap-2.5">
                                    @if ($row->profile_photo_url)
                                        <img src="{{ $row->profile_photo_url }}" alt="Photo de {{ $row->name }}" class="h-10 w-10 rounded-full object-cover ring-2 ring-white shadow-sm">
                                    @else
                                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-800 text-xs font-semibold text-white">
                                            {{ $row->profile_initials }}
                                        </span>
                                    @endif
                                    <div>
                                        <p class="font-medium text-slate-900">{{ $row->name }}</p>
                                        <p class="text-xs text-slate-600">{{ $row->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">
                                    {{ $row->roleLabel() }} ({{ $row->role }})
                                </span>
                            </td>
                            <td class="text-sm text-slate-600">{{ $row->profileScopeLabel() }}</td>
                            <td class="text-sm text-slate-700">
                                @if ($row->isAgent())
                                    <span class="inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                                        Agent
                                    </span>
                                    <div class="mt-1 text-xs text-slate-600">
                                        Matricule: {{ $row->agent_matricule ?: '-' }}<br>
                                        Fonction: {{ $row->agent_fonction ?: '-' }}<br>
                                        Tel: {{ $row->agent_telephone ?: '-' }}
                                    </div>
                                @else
                                    <span class="text-slate-500">-</span>
                                @endif
                            </td>
                            <td>{{ $row->direction?->code ? $row->direction->code . ' - ' . $row->direction->libelle : '-' }}</td>
                            <td>{{ $row->service?->code ? $row->service->code . ' - ' . $row->service->libelle : '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.referentiel.utilisateurs.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.referentiel.utilisateurs.destroy', $row) }}" onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-red-700 text-white hover:bg-red-600" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 8 : 7 }}" class="text-slate-600">Aucun utilisateur trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    </section>
@endsection
