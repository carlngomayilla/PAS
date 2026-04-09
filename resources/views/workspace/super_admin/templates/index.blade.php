@extends('layouts.workspace')

@section('title', 'Templates d export')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Templates d export</h1>
                <p class="mt-2 text-slate-600">Bibliotheque centrale des modeles PDF, Excel, Word et CSV.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.create') }}">Nouveau template</a>
            </div>
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <form method="GET" class="form-grid">
            <div><label for="q">Recherche</label><input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Nom, code, type de rapport"></div>
            <div><label for="format">Format</label><select id="format" name="format"><option value="">Tous</option>@foreach ($formatOptions as $option)<option value="{{ $option }}" @selected($filters['format'] === $option)>{{ strtoupper($option) }}</option>@endforeach</select></div>
            <div><label for="module">Module</label><select id="module" name="module"><option value="">Tous</option>@foreach ($moduleOptions as $option)<option value="{{ $option }}" @selected($filters['module'] === $option)>{{ $option }}</option>@endforeach</select></div>
            <div><label for="status">Statut</label><select id="status" name="status"><option value="">Tous</option>@foreach ($statusOptions as $option)<option value="{{ $option }}" @selected($filters['status'] === $option)>{{ $option }}</option>@endforeach</select></div>
            <div><label for="target_profile">Profil</label><select id="target_profile" name="target_profile"><option value="">Tous</option>@foreach ($profileOptions as $option)<option value="{{ $option }}" @selected($filters['target_profile'] === $option)>{{ $option }}</option>@endforeach</select></div>
            <div class="flex items-end gap-2"><button class="btn btn-primary" type="submit">Filtrer</button><a class="btn btn-secondary" href="{{ route('workspace.super-admin.templates.index') }}">Reset</a></div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
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

    <section class="ui-card mb-3.5">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr><th class="px-3 py-2 text-left">Nom</th><th class="px-3 py-2 text-left">Format</th><th class="px-3 py-2 text-left">Module</th><th class="px-3 py-2 text-left">Profil</th><th class="px-3 py-2 text-left">Niveau</th><th class="px-3 py-2 text-left">Statut</th><th class="px-3 py-2 text-left">Affectations</th><th class="px-3 py-2 text-left">Versions</th><th class="px-3 py-2 text-left">Actions</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-3 py-2"><div class="font-semibold">{{ $row->name }}</div><div class="text-xs text-slate-500">{{ $row->code }}</div></td>
                            <td class="px-3 py-2">{{ $row->formatLabel() }}</td>
                            <td class="px-3 py-2">{{ $row->module }}</td>
                            <td class="px-3 py-2">{{ $row->target_profile ?: 'Tous profils' }}</td>
                            <td class="px-3 py-2">{{ $row->reading_level ?: 'Non borne' }}</td>
                            <td class="px-3 py-2">{{ $row->statusLabel() }}</td>
                            <td class="px-3 py-2">{{ $row->assignments_count }}</td>
                            <td class="px-3 py-2">{{ $row->versions_count }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary !px-3 !py-1.5" href="{{ route('workspace.super-admin.templates.show', $row) }}">Voir</a>
                                    <a class="btn btn-primary !px-3 !py-1.5" href="{{ route('workspace.super-admin.templates.edit', $row) }}">Modifier</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-slate-500" colspan="9">Aucun template ne correspond aux filtres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </section>
@endsection

