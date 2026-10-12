@extends('layouts.workspace')

@section('content')
@php
    $rows = collect($preview['rows'] ?? []);
    $globalErrors = collect($preview['global_errors'] ?? []);
    $hasErrors = $globalErrors->isNotEmpty() || $rows->contains(fn ($row) => ($row['status'] ?? '') === 'Erreur');
    $isImported = $import->status === 'imported';
    $canConfirm = ! $hasErrors && $import->status === 'preview_ready';
    $confirmationState = $isImported ? 'done' : ($canConfirm ? 'current' : 'pending');
    $evidenceState = $isImported ? 'current' : 'pending';
@endphp
<div class="app-screen-flow">
    <x-ui.page-title
        class="app-screen-block"
        eyebrow="Données historiques"
        title="Vérification de la reprise"
        subtitle="{{ $import->filename }} · {{ $import->valid_rows }} lignes valides · {{ $import->error_rows }} erreurs"
    >
        <x-slot:actions>
            <a class="btn btn-outline min-h-10 px-4" href="{{ route('workspace.imports.historical.index') }}">Retour aux reprises</a>
        </x-slot:actions>
    </x-ui.page-title>

    <section class="app-card app-screen-block p-4 sm:p-5" aria-labelledby="historical-preview-steps-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="app-eyebrow">Parcours de reprise</p>
                <h2 id="historical-preview-steps-title" class="mt-1 text-base font-extrabold text-[var(--app-text)]">État du fichier et prochaine action</h2>
            </div>
            <span class="anbg-badge px-2 py-1 text-xs {{ $hasErrors ? 'anbg-badge-danger' : ($isImported ? 'anbg-badge-success' : 'anbg-badge-info') }}">{{ $hasErrors ? 'Corrections requises' : ($isImported ? 'Reprise importée' : 'Prête à confirmer') }}</span>
        </div>

        <ol class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Progression de la reprise historique">
            @foreach ([
                ['number' => '1', 'title' => 'Fichier reçu', 'copy' => 'Le fichier est conservé dans la reprise.', 'state' => 'done'],
                ['number' => '2', 'title' => 'Vérification', 'copy' => $hasErrors ? 'Corrigez les erreurs affichées ci-dessous.' : 'Les lignes ont été analysées.', 'state' => $hasErrors ? 'warning' : 'done'],
                ['number' => '3', 'title' => 'Confirmation', 'copy' => $isImported ? 'Les faits historiques ont été enregistrés.' : ($canConfirm ? 'Vous pouvez appliquer la reprise.' : 'Cette étape reste bloquée par les erreurs.'), 'state' => $confirmationState],
                ['number' => '4', 'title' => 'Justificatifs', 'copy' => $isImported ? 'Ajoutez les pièces dans les fiches actions.' : 'Disponible après l’import de la reprise.', 'state' => $evidenceState],
            ] as $step)
                @php
                    $stepTone = match ($step['state']) {
                        'done' => 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/70 dark:bg-emerald-950/20',
                        'current' => 'border-[var(--app-primary)] bg-[var(--app-primary-light)]',
                        'warning' => 'border-amber-200 bg-amber-50/70 dark:border-amber-900/70 dark:bg-amber-950/20',
                        default => 'border-[var(--app-border)] bg-[var(--app-surface-soft)]',
                    };
                    $markerTone = match ($step['state']) {
                        'done' => 'bg-emerald-600 text-white',
                        'warning' => 'bg-amber-500 text-amber-950',
                        'current' => 'bg-[var(--app-primary)] text-white',
                        default => 'bg-[var(--app-surface-muted)] text-[var(--app-muted)]',
                    };
                @endphp
                <li class="flex gap-3 rounded-xl border p-3 {{ $stepTone }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-black {{ $markerTone }}" aria-hidden="true">{{ $step['number'] }}</span>
                    <div class="min-w-0">
                        <p class="text-sm font-extrabold text-[var(--app-text)]">{{ $step['title'] }}</p>
                        <p class="mt-1 text-xs leading-5 text-[var(--app-muted)]">{{ $step['copy'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    @if (session('success'))
        <div class="app-screen-block rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/30 dark:text-emerald-200" role="status">
            {{ session('success') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="app-screen-block rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700 dark:border-red-900/70 dark:bg-red-950/30 dark:text-red-200" role="alert">
            {{ $errors->first() }}
        </div>
    @endif
    @if ($globalErrors->isNotEmpty())
        <section class="app-card app-screen-block p-4 sm:p-5" aria-labelledby="historical-format-errors-title">
            <h2 id="historical-format-errors-title" class="text-base font-extrabold text-[var(--app-text)]">Le fichier doit être corrigé</h2>
            <p class="mt-1 text-sm text-[var(--app-muted)]">Aucune confirmation n’est possible tant que le format reste invalide.</p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-red-800 dark:text-red-200">
                @foreach ($globalErrors as $globalError)
                    <li>{{ $globalError }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canConfirm)
        <x-ui.section-card
            class="app-screen-block"
            title="3. Confirmer la reprise"
            subtitle="Cette action met à jour les faits d’exécution et recalcule les indicateurs des actions concernées."
        >
            <form method="POST" action="{{ route('workspace.imports.historical.confirm', $import) }}" class="flex flex-col gap-4 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4 sm:flex-row sm:items-center sm:justify-between">
                @csrf
                <p class="max-w-3xl text-sm leading-6 text-[var(--app-muted)]">Après confirmation, les responsables complètent les justificatifs dans les fiches actions avant de poursuivre le circuit de validation habituel.</p>
                <button class="btn btn-primary min-h-10 shrink-0 px-5" type="submit" data-confirm-message="Confirmer la reprise des exécutions ?" data-confirm-tone="warning" data-confirm-label="Importer">Confirmer la reprise</button>
            </form>
        </x-ui.section-card>
    @elseif ($isImported)
        <section class="app-card app-screen-block p-4 sm:p-5" aria-labelledby="historical-evidence-title">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="app-eyebrow">Étape suivante</p>
                    <h2 id="historical-evidence-title" class="mt-1 text-base font-extrabold text-[var(--app-text)]">Ajouter les justificatifs</h2>
                    <p class="mt-1 text-sm text-[var(--app-muted)]">{{ $import->updated_count }} action(s) ont été mises à jour. Les pièces se déposent dans chaque fiche action.</p>
                </div>
                <a class="btn btn-secondary min-h-10 shrink-0 px-5" href="{{ route('workspace.actions.index') }}">Ouvrir les actions</a>
            </div>
        </section>
    @endif

    <x-ui.section-card
        class="app-screen-block"
        title="Résultat ligne par ligne"
        subtitle="Utilisez ce contrôle pour corriger le fichier avant confirmation."
    >
        <div class="app-table-wrapper overflow-x-auto" tabindex="0" role="region" aria-label="Résultat de la vérification des lignes">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th scope="col">Ligne</th>
                        <th scope="col">Résultat</th>
                        <th scope="col">Code action</th>
                        <th scope="col">Action</th>
                        <th scope="col">Statut</th>
                        <th scope="col">Début réel</th>
                        <th scope="col">Fin réelle</th>
                        <th scope="col">Progression</th>
                        <th scope="col">Message</th>
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
                        <tr>
                            <td colspan="9" class="py-10 text-center text-[var(--app-muted)]">Aucune ligne à afficher.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.section-card>
</div>
@endsection
