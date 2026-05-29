@extends('layouts.workspace')

@section('content')
@php
    $rows = collect($preview['rows'] ?? []);
    $hasErrors = $rows->contains(fn ($row) => ($row['status'] ?? '') === 'Erreur');
    $mappingRequired = $import->status === 'mapping_required';
    $rawHeaders = collect($preview['headers'] ?? []);
    $requiredColumns = collect($preview['required_columns'] ?? \App\Services\Imports\PlanningExcelImportService::REQUIRED_COLUMNS);
    $suggestedMapping = collect($preview['suggested_mapping'] ?? []);
@endphp
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">{{ $mappingRequired ? 'Correspondance des colonnes' : 'Previsualisation' }}</h1>
                <p class="text-sm text-slate-500">{{ $import->filename }} - {{ $import->valid_rows }} lignes valides, {{ $import->error_rows }} erreurs.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($import->error_rows > 0)
                    <a class="btn btn-secondary" href="{{ route('workspace.imports.error-report', $import) }}">Telecharger le rapport d'erreurs</a>
                @endif
                <a class="btn btn-outline" href="{{ route('workspace.imports.index') }}">Historique</a>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        @if ($mappingRequired)
            <div class="mb-4 rounded-lg border border-[#d8ecf8] bg-[#f8fbfe] p-4">
                <h2 class="mb-2 text-base font-extrabold text-[#1c203d]">Regles strictes du fichier</h2>
                <div class="grid gap-3 text-sm text-slate-600 md:grid-cols-2">
                    <div class="rounded-lg bg-white p-3">
                        <p class="font-bold text-[#1c203d]">Obligatoire</p>
                        <p>Chaque colonne metier ci-dessous doit etre associee a une colonne de votre fichier.</p>
                    </div>
                    <div class="rounded-lg bg-white p-3">
                        <p class="font-bold text-[#1c203d]">Interdit</p>
                        <p>Le fichier ne doit pas contenir de colonnes <code>code_*</code> ni <code>rmo_prevu</code>. Utilisez <code>codes_agents_rmo</code>.</p>
                    </div>
                    <div class="rounded-lg bg-white p-3">
                        <p class="font-bold text-[#1c203d]">Structure</p>
                        <p>Une seule feuille, un seul PAS, une ligne egale une action planifiee.</p>
                    </div>
                    <div class="rounded-lg bg-white p-3">
                        <p class="font-bold text-[#1c203d]">Insertion</p>
                        <p>Aucune donnee n'est inseree avant la previsualisation et la confirmation.</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('workspace.imports.mapping', $import) }}" class="mb-4 rounded-lg border border-[#d8ecf8] bg-white p-4">
                @csrf
                <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-extrabold text-[#1c203d]">Associer les colonnes</h2>
                        <p class="text-sm text-slate-500">A gauche : colonne attendue par l'application. A droite : colonne presente dans votre fichier.</p>
                    </div>
                    <button class="btn btn-primary" type="submit">Previsualiser les donnees</button>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($requiredColumns as $column)
                        @php
                            $selected = old("mapping.$column", $suggestedMapping->get($column, ''));
                        @endphp
                        <div class="rounded-lg border border-[#e5eef7] p-3">
                            <label for="mapping_{{ $column }}" class="text-xs font-extrabold uppercase text-[#1c203d]">{{ $column }}</label>
                            <select id="mapping_{{ $column }}" name="mapping[{{ $column }}]" required class="mt-2">
                                <option value="">Choisir la colonne du fichier</option>
                                @foreach ($rawHeaders as $header)
                                    <option value="{{ $header }}" @selected($selected === $header)>{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <button class="btn btn-primary" type="submit">Previsualiser les donnees</button>
                </div>
            </form>

            @if (! empty($preview['sample_rows'] ?? []))
                <div class="app-table-wrapper overflow-x-auto">
                    <table class="app-table data-table">
                        <thead>
                            <tr>
                                @foreach ($rawHeaders as $header)
                                    <th>{{ $header }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (($preview['sample_rows'] ?? []) as $sample)
                                <tr>
                                    @foreach ($rawHeaders as $header)
                                        <td>{{ $sample[$header] ?? '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif (! $hasErrors)
            <form method="POST" action="{{ route('workspace.imports.confirm', $import) }}" class="mb-4 flex flex-col gap-3 rounded border border-[#d8ecf8] bg-[#f8fbfe] p-3 sm:flex-row sm:items-end">
                @csrf
                <div class="min-w-[260px]">
                    <label for="mode">Mode d'import</label>
                    <select id="mode" name="mode">
                        @foreach ($modes as $value => $label)
                            <option value="{{ $value }}" @selected($value === 'create_only')>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">Confirmer l'import</button>
            </form>
        @endif

        @if (! $mappingRequired)
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Ligne</th>
                        <th>Statut</th>
                        <th>Direction</th>
                        <th>Service</th>
                        <th>Objectif operationnel</th>
                        <th>Action</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $data = $row['data'] ?? []; @endphp
                        <tr>
                            <td>{{ $row['line'] ?? '-' }}</td>
                            <td><span class="anbg-badge px-2 py-0.5 text-xs {{ ($row['status'] ?? '') === 'Erreur' ? 'anbg-badge-danger' : (($row['status'] ?? '') === 'Avertissement' ? 'anbg-badge-warning' : 'anbg-badge-success') }}">{{ $row['status'] ?? '-' }}</span></td>
                            <td>{{ $data['direction'] ?? '-' }}</td>
                            <td>{{ $data['service_unite'] ?? '-' }}</td>
                            <td>{{ $data['libelle_objectif_operationnel'] ?? '-' }}</td>
                            <td>{{ $data['libelle_action'] ?? '-' }}</td>
                            <td>{{ $row['message'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
</div>
@endsection
