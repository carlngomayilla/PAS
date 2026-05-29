@extends('layouts.workspace')

@section('title', 'Exercices et periodes')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <span class="showcase-eyebrow">Super Admin</span>
                <h1 class="mt-2">Exercices et periodes</h1>
                <p class="mt-2 text-sm text-slate-600">L exercice actif pilote le filtre par defaut des dashboards, KPI et rapports.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-4">
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Exercice actif</p>
            <p class="mt-2 text-3xl font-bold text-slate-900">{{ $activeExercise?->annee ?? '-' }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $activeExercise?->libelle ?? 'Aucun exercice actif defini' }}</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Periode active</p>
            <p class="mt-2 text-xl font-bold text-slate-900">
                {{ $activeExercise?->date_debut?->format('d/m/Y') ?? '-' }} - {{ $activeExercise?->date_fin?->format('d/m/Y') ?? '-' }}
            </p>
            <p class="mt-1 text-sm text-slate-600">{{ $activeExercise ? ($statusOptions[$activeExercise->statut] ?? $activeExercise->statut) : '-' }}</p>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2>Archivage automatique PAO / PTA</h2>
                <p class="mt-2 text-sm text-slate-600">Les PAO et PTA clotures passent automatiquement en archive apres la duree parametree.</p>
            </div>
            <div class="grid gap-2 text-sm [grid-template-columns:repeat(auto-fit,minmax(130px,1fr))] lg:min-w-[360px]">
                <article class="rounded-lg border border-slate-200/85 bg-white/95 p-3">
                    <span class="text-xs font-bold uppercase text-slate-500">PAO eligibles</span>
                    <strong class="mt-1 block text-2xl text-slate-900">{{ (int) data_get($archiveSummary, 'counts.paos', 0) }}</strong>
                </article>
                <article class="rounded-lg border border-slate-200/85 bg-white/95 p-3">
                    <span class="text-xs font-bold uppercase text-slate-500">PTA eligibles</span>
                    <strong class="mt-1 block text-2xl text-slate-900">{{ (int) data_get($archiveSummary, 'counts.ptas', 0) }}</strong>
                </article>
            </div>
        </div>

        <form method="POST" action="{{ route('workspace.super-admin.exercises.archive-settings.update') }}" class="mt-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
            @csrf
            @method('PUT')
            <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="planning_auto_archive_enabled" value="1" @checked(($archiveSettings['planning_auto_archive_enabled'] ?? '1') === '1')>
                <span>
                    <strong class="block text-slate-900">Activer l archivage automatique</strong>
                    <span class="mt-1 block text-slate-500">Execution quotidienne via la commande planifiee.</span>
                </span>
            </label>
            <div>
                <label for="planning_pao_archive_after_days">Delai PAO cloture vers archive</label>
                <input id="planning_pao_archive_after_days" name="planning_pao_archive_after_days" type="number" min="1" max="3650" value="{{ old('planning_pao_archive_after_days', $archiveSettings['planning_pao_archive_after_days'] ?? 30) }}" required>
            </div>
            <div>
                <label for="planning_pta_archive_after_days">Delai PTA cloture vers archive</label>
                <input id="planning_pta_archive_after_days" name="planning_pta_archive_after_days" type="number" min="1" max="3650" value="{{ old('planning_pta_archive_after_days', $archiveSettings['planning_pta_archive_after_days'] ?? 30) }}" required>
            </div>
            <div class="flex items-end">
                <button class="btn btn-primary w-full" type="submit">Enregistrer</button>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Nouvel exercice</h2>
        <form method="POST" action="{{ route('workspace.super-admin.exercises.store') }}" class="mt-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
            @csrf
            <div>
                <label for="annee">Annee</label>
                <input id="annee" name="annee" type="number" min="2000" max="2100" value="{{ old('annee', now()->year + 1) }}" required>
            </div>
            <div>
                <label for="libelle">Libelle</label>
                <input id="libelle" name="libelle" type="text" value="{{ old('libelle', 'Exercice '.(now()->year + 1)) }}" required>
            </div>
            <div>
                <label for="date_debut">Date debut</label>
                <input id="date_debut" name="date_debut" type="date" value="{{ old('date_debut', (now()->year + 1).'-01-01') }}" required>
            </div>
            <div>
                <label for="date_fin">Date fin</label>
                <input id="date_fin" name="date_fin" type="date" value="{{ old('date_fin', (now()->year + 1).'-12-31') }}" required>
            </div>
            <div>
                <label for="statut">Statut</label>
                <select id="statut" name="statut" required>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('statut', 'ouvert') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label for="motif">Motif</label>
                <input id="motif" name="motif" type="text" value="{{ old('motif') }}" placeholder="Creation de l exercice de pilotage" required>
            </div>
            <div class="flex items-end">
                <button class="btn btn-primary w-full" type="submit">Creer</button>
            </div>
        </form>
    </section>

    <section class="showcase-panel">
        <h2>Registre des exercices</h2>
        <div class="mt-4 space-y-3">
            @forelse ($exerciseRows as $row)
                <article class="rounded-lg border border-slate-200/85 bg-white/95 p-4">
                    <form method="POST" action="{{ route('workspace.super-admin.exercises.update', $row) }}" class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
                        @csrf
                        @method('PUT')
                        <div>
                            <label for="exercise_{{ $row->id }}_annee">Annee</label>
                            <input id="exercise_{{ $row->id }}_annee" name="annee" type="number" min="2000" max="2100" value="{{ old('annee', $row->annee) }}" required>
                        </div>
                        <div>
                            <label for="exercise_{{ $row->id }}_libelle">Libelle</label>
                            <input id="exercise_{{ $row->id }}_libelle" name="libelle" type="text" value="{{ old('libelle', $row->libelle) }}" required>
                        </div>
                        <div>
                            <label for="exercise_{{ $row->id }}_date_debut">Date debut</label>
                            <input id="exercise_{{ $row->id }}_date_debut" name="date_debut" type="date" value="{{ old('date_debut', $row->date_debut?->format('Y-m-d')) }}" required>
                        </div>
                        <div>
                            <label for="exercise_{{ $row->id }}_date_fin">Date fin</label>
                            <input id="exercise_{{ $row->id }}_date_fin" name="date_fin" type="date" value="{{ old('date_fin', $row->date_fin?->format('Y-m-d')) }}" required>
                        </div>
                        <div>
                            <label for="exercise_{{ $row->id }}_statut">Statut</label>
                            <select id="exercise_{{ $row->id }}_statut" name="statut" required>
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('statut', $row->statut) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="exercise_{{ $row->id }}_motif">Motif</label>
                            <input id="exercise_{{ $row->id }}_motif" name="motif" type="text" placeholder="Ajustement de periode" required>
                        </div>
                        <div class="flex items-end gap-2">
                            <button class="btn btn-secondary w-full" type="submit">Enregistrer</button>
                            @if ($row->is_active)
                                <span class="anbg-badge anbg-badge-success px-3 py-2 text-xs">Actif</span>
                            @endif
                        </div>
                    </form>

                    @if (! $row->is_active)
                        <form method="POST" action="{{ route('workspace.super-admin.exercises.activate', $row) }}" class="mt-3 flex flex-col gap-2 md:flex-row md:items-end">
                            @csrf
                            <div class="flex-1">
                                <label for="activate_{{ $row->id }}_motif">Motif activation</label>
                                <input id="activate_{{ $row->id }}_motif" name="motif" type="text" placeholder="Definition de l exercice actif global" required>
                            </div>
                            <button class="btn btn-primary" type="submit" @disabled($row->statut === 'archive')>Definir actif</button>
                        </form>
                    @endif
                </article>
            @empty
                <x-ui.empty-state
                    title="Aucun exercice"
                    message="Creez un exercice pour piloter les periodes, les KPI et les rapports."
                    icon="calendar"
                    tone="info"
                />
            @endforelse
        </div>
    </section>
@endsection
