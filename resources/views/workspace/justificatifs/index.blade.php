@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Gestion des justificatifs</h1>
        <p class="text-slate-600">Recherche, suivi et gestion documentaire des preuves d execution.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" href="{{ route('workspace.justificatifs.create') }}">Ajouter un justificatif</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.justificatifs.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="type">Type</label>
                    <select id="type" name="type">
                        <option value="">Tous</option>
                        @foreach ($typeOptions as $key => $label)
                            <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="entite_id">ID entite</label>
                    <input id="entite_id" name="entite_id" type="number" value="{{ $entiteId }}">
                </div>
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $q }}" placeholder="Nom, description, mime">
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.justificatifs.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Entite</th>
                        <th>Fichier</th>
                        <th>Description</th>
                        <th>Ajoute par</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($justificatifs as $item)
                        @php
                            $typeAlias = match ($item->justifiable_type) {
                                \App\Models\Action::class => 'action',
                                \App\Models\Kpi::class => 'kpi',
                                \App\Models\KpiMesure::class => 'kpi_mesure',
                                default => $item->justifiable_type,
                            };
                        @endphp
                        <tr>
                            <td>{{ $item->id }}</td>
                            <td><span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $typeAlias }}</span></td>
                            <td>#{{ $item->justifiable_id }}</td>
                            <td>
                                <strong>{{ $item->nom_original }}</strong><br>
                                <span class="text-slate-600">{{ $item->mime_type }} | {{ number_format(($item->taille_octets ?? 0) / 1024, 1) }} Ko</span>
                            </td>
                            <td>{{ $item->description ?: '-' }}</td>
                            <td>{{ $item->ajoutePar?->name ?? '-' }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.justificatifs.download', $item) }}">Telecharger</a>
                                    @if ($canWriteByJustificatif[$item->id] ?? false)
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.justificatifs.edit', $item) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.justificatifs.destroy', $item) }}" onsubmit="return confirm('Supprimer ce justificatif ?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-red btn-sm" type="submit">Supprimer</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-slate-600">Aucun justificatif disponible.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $justificatifs->links() }}
        </div>
    </section>
@endsection
