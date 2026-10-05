@extends('layouts.workspace')

@section('content')
@php
    $errorRows = collect($import->error_report ?? []);
    $previewRows = collect($import->preview_payload['rows'] ?? []);
    $validatedRows = $previewRows->where('status', 'Valide')->count();
    $warningRows = $previewRows->where('status', 'Avertissement')->count();
    $statusLabel = match ((string) $import->status) {
        'imported' => 'Import confirmé',
        'preview_ready' => 'Prêt à confirmer',
        'preview_errors' => 'Correction requise',
        'mapping_required' => 'Correspondance requise',
        default => ucfirst((string) $import->status),
    };
    $modeLabels = [
        \App\Models\PlanningImport::MODE_CREATE_ONLY => 'Créer uniquement',
        \App\Models\PlanningImport::MODE_SKIP_DUPLICATES => 'Ignorer les doublons',
        \App\Models\PlanningImport::MODE_UPDATE_EXISTING => 'Mettre à jour si existe',
    ];
@endphp

<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <span class="anbg-badge anbg-badge-success px-2 py-0.5 text-xs">{{ $statusLabel }}</span>
                <h1 class="showcase-panel-title mt-3">Bilan du lot d'import #{{ $import->id }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">
                    {{ $import->filename }} · {{ $modeLabels[$import->mode] ?? $import->mode ?? 'Mode non renseigné' }} · lancé par {{ $import->user?->name ?? 'Utilisateur non renseigné' }}
                    @if ($import->updated_at)
                        le {{ $import->updated_at->format('d/m/Y H:i') }}
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a class="btn btn-secondary" href="{{ route('workspace.imports.error-report', $import) }}">Télécharger le rapport de validation</a>
                @if ($errorRows->isNotEmpty())
                    <a class="btn btn-outline" href="{{ route('workspace.imports.errors', $import) }}">Ouvrir les lignes rejetées</a>
                @endif
                <a class="btn btn-outline" href="{{ route('workspace.imports.show', $import) }}">Revoir l'aperçu</a>
            </div>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.stat-card title="Lignes analysées" :value="$import->total_rows" />
            <x-ui.stat-card title="Valides" :value="$validatedRows ?: $import->valid_rows" />
            <x-ui.stat-card title="Avertissements" :value="$warningRows" />
            <x-ui.stat-card title="Créées" :value="$import->created_count" />
            <x-ui.stat-card title="Mises à jour" :value="$import->updated_count" />
            <x-ui.stat-card title="Ignorées" :value="$import->skipped_count" />
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.45fr)]">
            <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" aria-labelledby="import-quality-title">
                <h2 id="import-quality-title" class="text-base font-bold text-[#17324a] dark:text-slate-100">Contrôle du lot</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                        <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Numéro de lot</p>
                        <p class="mt-1 text-lg font-extrabold text-[#17324a] dark:text-slate-100">#{{ $import->id }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                        <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Lignes rejetées</p>
                        <p class="mt-1 text-lg font-extrabold {{ $errorRows->isNotEmpty() ? 'text-[#B42318] dark:text-red-300' : 'text-[#16703c] dark:text-emerald-300' }}">{{ $errorRows->count() }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                        <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Adresse IP</p>
                        <p class="mt-1 break-words text-sm font-bold text-[#17324a] dark:text-slate-100">{{ $import->ip_address ?: 'Non renseignée' }}</p>
                    </div>
                </div>

                <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">
                    Le rapport de validation reprend les lignes rejetées et les corrections attendues. Les lignes ignorées correspondent au mode choisi, par exemple un doublon laissé hors import.
                </p>
            </section>

            <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" aria-labelledby="import-next-step-title">
                <h2 id="import-next-step-title" class="text-base font-bold text-[#17324a] dark:text-slate-100">Suite opérationnelle</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Ouvrez les PTA pour vérifier les actions créées ou mises à jour, puis finalisez le paramétrage des actions importées avant le suivi d'exécution.
                </p>
                <div class="mt-4 grid gap-2">
                    <a class="btn btn-primary" href="{{ route('workspace.pta.index') }}">Voir les PTA</a>
                    <a class="btn btn-secondary" href="{{ route('workspace.imports.index') }}">Retour à l'historique</a>
                </div>
            </section>
        </div>

        <div class="mt-5 grid min-w-0 gap-4 xl:grid-cols-2">
            @foreach (['axes' => 'Répartition importée par axe', 'services' => 'Répartition importée par service'] as $group => $title)
                <section class="min-w-0 rounded-lg border border-slate-200 p-3 dark:border-slate-700" aria-labelledby="result-breakdown-{{ $group }}">
                    <h2 id="result-breakdown-{{ $group }}" class="mb-2 text-base font-semibold text-[#17324a] dark:text-slate-100">{{ $title }}</h2>
                    <div class="app-table-wrapper overflow-x-auto" tabindex="0" role="region" aria-label="{{ $title }}">
                        <table class="app-table data-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ $group === 'axes' ? 'Axe du fichier' : 'Direction et service' }}</th>
                                    <th scope="col">Lignes</th>
                                    <th scope="col">Valides</th>
                                    <th scope="col">Avertissements</th>
                                    <th scope="col">Erreurs</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse (($breakdown[$group] ?? []) as $item)
                                    <tr>
                                        <th scope="row">
                                            <span class="block text-xs text-slate-500">{{ $item['context'] }}</span>
                                            {{ $item['label'] }}
                                        </th>
                                        <td>{{ $item['total'] }}</td>
                                        <td>{{ $item['valid'] }}</td>
                                        <td>{{ $item['warnings'] }}</td>
                                        <td>{{ $item['errors'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">Aucune ligne à afficher.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    </section>
</div>
@endsection
