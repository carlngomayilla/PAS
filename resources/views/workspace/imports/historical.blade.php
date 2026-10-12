@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <x-ui.page-title
        class="app-screen-block"
        eyebrow="Données historiques"
        title="Reprise des exécutions"
        subtitle="Enregistrez les faits déjà constatés sans recréer les actions du PTA."
    >
        <x-slot:actions>
            <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.imports.historical.template') }}">Télécharger le modèle</a>
            <a class="btn btn-outline min-h-10 px-4" href="{{ route('workspace.imports.historical.template', ['prefill' => 1]) }}">Modèle prérempli</a>
            <a class="btn btn-outline min-h-10 px-4" href="{{ route('workspace.imports.index') }}">Imports de planification</a>
        </x-slot:actions>
    </x-ui.page-title>

    <details class="app-card app-screen-block p-4 sm:p-5 action-advanced-filters" data-historical-import-help>
        <summary class="cursor-pointer font-semibold text-[var(--app-text)]">Comment préparer et intégrer une reprise historique</summary>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="app-eyebrow">Parcours sécurisé</p>
                <h2 id="historical-import-steps-title" class="mt-1 text-base font-extrabold text-[var(--app-text)]">Du fichier aux justificatifs</h2>
            </div>
            <p class="text-sm text-[var(--app-muted)]">Aucune donnée n’est modifiée avant la confirmation.</p>
        </div>

        <ol class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Étapes de la reprise historique">
            @foreach ([
                ['number' => '1', 'title' => 'Préparer', 'copy' => 'Téléchargez le modèle et renseignez les codes actions existants.', 'state' => 'current'],
                ['number' => '2', 'title' => 'Vérifier', 'copy' => 'Contrôlez les lignes valides, les erreurs et les avertissements.', 'state' => 'pending'],
                ['number' => '3', 'title' => 'Confirmer', 'copy' => 'Appliquez la reprise seulement après le contrôle du fichier.', 'state' => 'pending'],
                ['number' => '4', 'title' => 'Justifier', 'copy' => 'Déposez ensuite les pièces dans les fiches actions.', 'state' => 'pending'],
            ] as $step)
                <li class="flex gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 {{ $step['state'] === 'current' ? 'ring-1 ring-[var(--app-primary)]/30' : '' }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--app-primary)] text-xs font-black text-white" aria-hidden="true">{{ $step['number'] }}</span>
                    <div class="min-w-0">
                        <p class="text-sm font-extrabold text-[var(--app-text)]">{{ $step['title'] }}</p>
                        <p class="mt-1 text-xs leading-5 text-[var(--app-muted)]">{{ $step['copy'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </details>

    <x-ui.section-card
        class="app-screen-block"
        title="1. Choisir le fichier de reprise"
        subtitle="Le fichier est analysé avant toute mise à jour des actions."
    >
        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700 dark:border-red-900/70 dark:bg-red-950/30 dark:text-red-200" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.imports.historical.preview') }}" class="form-shell reco-form-shell">
            @csrf
            <section class="form-section reco-form-section" aria-labelledby="historical-upload-title">
                <h2 id="historical-upload-title" class="form-section-title">Fichier de reprise</h2>
                <div class="form-grid reco-form-grid">
                <div class="min-w-0 flex-1">
                    <label for="historical-file" class="block text-sm font-extrabold text-[var(--app-text)]">Fichier Excel de reprise</label>
                    <p class="mt-1 text-sm text-[var(--app-muted)]">Formats acceptés : .xlsx ou .csv, une ligne par action existante.</p>
                    <input id="historical-file" name="file" type="file" accept=".xlsx,.csv" required class="mt-3 block w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] p-3 text-sm text-[var(--app-text)] file:mr-3 file:rounded-md file:border-0 file:bg-[var(--app-primary-light)] file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[var(--app-primary)] hover:file:bg-[var(--app-primary-light)]">
                    @error('file')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <p class="col-span-full border-t border-[var(--app-border)] pt-3 text-xs leading-5 text-[var(--app-muted)]">Utilisez le modèle prérempli pour conserver les codes réels. Les dates inconnues restent vides ; les justificatifs sont déposés après la confirmation dans les fiches actions.</p>
                </div>
            </section>
            <footer class="form-actions reco-form-footer">
                <button class="btn btn-primary min-h-10 shrink-0 px-5" type="submit">Vérifier le fichier</button>
            </footer>
        </form>
    </x-ui.section-card>

    <x-ui.section-card
        class="app-screen-block"
        title="Reprises enregistrées"
        subtitle="Consultez une vérification en cours ou l’historique des reprises déjà importées."
    >
        <div class="app-table-wrapper overflow-x-auto" tabindex="0" role="region" aria-label="Historique des reprises d’exécutions">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Fichier</th>
                        <th scope="col">Importateur</th>
                        <th scope="col">Lignes</th>
                        <th scope="col">Statut</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($imports as $import)
                        <tr>
                            <td>{{ $import->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="font-semibold text-[var(--app-text)]">{{ $import->filename }}</td>
                            <td>{{ $import->user?->name ?? '-' }}</td>
                            <td>{{ $import->valid_rows }} valides / {{ $import->error_rows }} erreurs</td>
                            <td><span class="anbg-badge anbg-badge-info px-2 py-0.5 text-xs">{{ str_replace('_', ' ', $import->status) }}</span></td>
                            <td class="text-right">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <a class="btn btn-outline" href="{{ route('workspace.imports.historical.show', $import) }}">Voir</a>
                                    <form method="POST" action="{{ route('workspace.imports.historical.destroy', $import) }}" data-confirm-message="Supprimer cet import ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-danger" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-10 text-center">
                                <p class="font-semibold text-[var(--app-text)]">Aucune reprise historique enregistrée.</p>
                                <p class="mt-1 text-sm text-[var(--app-muted)]">Téléchargez le modèle, puis vérifiez votre premier fichier.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $imports->links() }}</div>
    </x-ui.section-card>
</div>
@endsection
