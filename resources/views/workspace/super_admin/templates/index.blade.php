@extends('layouts.workspace')

@section('title', 'Templates d export')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Templates d export</h1>
                <p class="mt-2 text-slate-600">Bibliothèque centrale des modèles PDF, Excel, Word et CSV.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.create') }}">Nouveau template</a>
            </div>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <form method="GET" class="form-grid">
            <div><label for="q">Recherche</label><input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Nom, code, type de rapport"></div>
            <div><label for="format">Format</label><select id="format" name="format"><option value="">Tous</option>@foreach ($formatOptions as $option)<option value="{{ $option }}" @selected($filters['format'] === $option)>{{ strtoupper($option) }}</option>@endforeach</select></div>
            <div><label for="module">Module</label><select id="module" name="module"><option value="">Tous</option>@foreach ($moduleOptions as $option)<option value="{{ $option }}" @selected($filters['module'] === $option)>{{ $option }}</option>@endforeach</select></div>
            <div><label for="status">Statut</label><select id="status" name="status"><option value="">Tous</option>@foreach ($statusOptions as $option)<option value="{{ $option }}" @selected($filters['status'] === $option)>{{ $option }}</option>@endforeach</select></div>
            <div><label for="target_profile">Profil</label><select id="target_profile" name="target_profile"><option value="">Tous</option>@foreach ($profileOptions as $option)<option value="{{ $option }}" @selected($filters['target_profile'] === $option)>{{ $option }}</option>@endforeach</select></div>
            <div class="flex items-end gap-2"><button class="btn btn-primary" type="submit">Filtrer</button><a class="btn btn-secondary" href="{{ route('workspace.super-admin.templates.index') }}">Reset</a></div>
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Importer un template JSON</h2>
        <form method="POST" action="{{ route('workspace.super-admin.templates.import-json') }}" enctype="multipart/form-data" class="mt-4 form-grid">
            @csrf
            <div class="md:col-span-2 xl:col-span-4">
                <label for="template_json">JSON colle</label>
                <textarea id="template_json" name="template_json" rows="6" placeholder='{"code":"reporting-template","name":"Template reporting"}'>{{ old('template_json') }}</textarea>
            </div>
            <div>
                <label for="template_file">Fichier JSON</label>
                <input id="template_file" name="template_file" type="file" accept=".json,application/json,text/plain">
            </div>
            <div class="flex items-end">
                <button class="btn btn-primary" type="submit">Importer en brouillon</button>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <div class="app-table-wrapper">
            <table class="app-table data-table">
                <thead><tr><th>Nom</th><th>Format</th><th>Module</th><th>Profil</th><th>Niveau</th><th>Statut</th><th>Affectations</th><th>Versions</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td><div class="font-semibold">{{ $row->name }}</div><div class="text-xs text-slate-500">{{ $row->code }}</div></td>
                            <td>{{ $row->formatLabel() }}</td>
                            <td>{{ $row->module }}</td>
                            <td>{{ $row->target_profile ?: 'Tous profils' }}</td>
                            <td>{{ $row->reading_level ?: 'Non borne' }}</td>
                            <td>{{ $row->statusLabel() }}</td>
                            <td>{{ $row->assignments_count }}</td>
                            <td>{{ $row->versions_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary !px-3 !py-1.5" href="{{ route('workspace.super-admin.templates.show', $row) }}">Voir</a>
                                    <a class="btn btn-primary !px-3 !py-1.5" href="{{ route('workspace.super-admin.templates.edit', $row) }}">Modifier</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-ui.empty-state
                                    title="Aucun template trouvé"
                                    message="Aucun modèle d'export ne correspond aux filtres courants."
                                    icon="file"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </section>
@endsection
