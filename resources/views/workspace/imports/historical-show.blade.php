@extends('layouts.workspace')

@section('content')
@php
    $rows = collect($preview['rows'] ?? []);
    $globalErrors = collect($preview['global_errors'] ?? []);
    $hasErrors = $globalErrors->isNotEmpty() || $rows->contains(fn ($row) => ($row['status'] ?? '') === 'Erreur');
@endphp
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Verification de la reprise</h1>
                <p class="text-sm text-slate-500">{{ $import->filename }} — {{ $import->valid_rows }} lignes valides, {{ $import->error_rows }} erreurs.</p>
            </div>
            <a class="btn btn-outline" href="{{ route('workspace.imports.historical.index') }}">Retour</a>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif
        @if ($globalErrors->isNotEmpty())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <p class="font-bold">Le fichier ne respecte pas le format attendu :</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($globalErrors as $globalError)
                        <li>{{ $globalError }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! $hasErrors && $import->status === 'preview_ready')
            <form method="POST" action="{{ route('workspace.imports.historical.confirm', $import) }}" class="mb-5 flex flex-col gap-3 rounded border border-emerald-200 bg-emerald-50/50 p-4 sm:flex-row sm:items-center sm:justify-between">
                @csrf
                <p class="text-sm text-emerald-950">La confirmation met a jour les actions existantes et recalcule leurs indicateurs. Les justificatifs seront ajoutes ensuite dans l’application.</p>
                <button class="btn btn-primary shrink-0" type="submit" data-confirm-message="Confirmer la reprise des executions ?" data-confirm-tone="warning" data-confirm-label="Importer">Importer la reprise</button>
            </form>
        @elseif ($import->status === 'imported')
            <div class="mb-5 rounded border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">Reprise importee. {{ $import->updated_count }} action(s) mise(s) a jour. Ajoutez maintenant les justificatifs dans leurs fiches.</div>
        @endif

        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Ligne</th>
                        <th>Résultat</th>
                        <th>Code action</th>
                        <th>Action</th>
                        <th>Statut</th>
                        <th>Début réel</th>
                        <th>Fin réelle</th>
                        <th>Progression</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $data = $row['data'] ?? []; @endphp
                        <tr>
                            <td>{{ $row['line'] ?? '-' }}</td>
                            <td><span class="anbg-badge px-2 py-0.5 text-xs {{ ($row['status'] ?? '') === 'Erreur' ? 'anbg-badge-danger' : (($row['status'] ?? '') === 'Avertissement' ? 'anbg-badge-warning' : 'anbg-badge-success') }}">{{ $row['status'] ?? '-' }}</span></td>
                            <td class="font-mono text-xs">{{ $data['code_action'] ?? '-' }}</td>
                            <td>{{ $data['action_libelle'] ?? '-' }}</td>
                            <td>{{ $data['statut_execution'] ?? '-' }}</td>
                            <td>{{ $data['date_debut_reelle'] ?? '-' }}</td>
                            <td>{{ $data['date_fin_reelle'] ?? '-' }}</td>
                            <td>{{ $data['progression_reelle'] ?? '-' }} %</td>
                            <td>{{ $row['message'] ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-8 text-center text-slate-500">Aucune ligne a afficher.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
