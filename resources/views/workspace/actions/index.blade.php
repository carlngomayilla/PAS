@extends('layouts.workspace')

@section('content')
    @php
        $listing = collect($rows->items());
        $avgProgression = $listing->avg(fn ($item) => (float) ($item->progression_reelle ?? 0)) ?? 0;
        $avgKpi = $listing->avg(fn ($item) => (float) ($item->actionKpi?->kpi_global ?? 0)) ?? 0;
        $fundedCount = $listing->where('financement_requis', true)->count();
        $statusCounts = [
            'en_retard' => $listing->where('statut_dynamique', 'en_retard')->count(),
            'en_cours' => $listing->where('statut_dynamique', 'en_cours')->count(),
            'achevees' => $listing->filter(fn ($item) => in_array($item->statut_dynamique, ['acheve_dans_delai', 'acheve_hors_delai'], true))->count(),
        ];
        $statusStyles = [
            'non_demarre' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
            'en_cours' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
            'en_avance' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            'en_retard' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
            'acheve_dans_delai' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            'acheve_hors_delai' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        ];
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">Execution operationnelle</span>
                <h1 class="showcase-title">Actions</h1>
                <p class="showcase-subtitle">
                    Pilotage des actions rattachees aux PTA avec suivi periodique intelligent, progression reelle vs theorique,
                    circuit de validation et KPI consolides.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        {{ $rows->total() }} actions dans le perimetre courant
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-red-500"></span>
                        {{ $statusCounts['en_retard'] }} en retard sur cette page
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-emerald-500"></span>
                        {{ $statusCounts['achevees'] }} achevees sur cette page
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-blue rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.create') }}">
                        Nouvelle action
                    </a>
                @endif
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.pilotage') }}">
                    Voir le pilotage
                </a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Listing courant</p>
            <p class="showcase-kpi-number">{{ $listing->count() }}</p>
            <p class="showcase-kpi-meta">Elements affiches sur cette page</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Progression moyenne</p>
            <p class="showcase-kpi-number">{{ number_format($avgProgression, 1) }}%</p>
            <p class="showcase-kpi-meta">{{ $statusCounts['en_cours'] }} actions en cours</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">KPI global moyen</p>
            <p class="showcase-kpi-number">{{ number_format($avgKpi, 1) }}</p>
            <p class="showcase-kpi-meta">Lecture directe de la performance courante</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Financement requis</p>
            <p class="showcase-kpi-number">{{ $fundedCount }}</p>
            <p class="showcase-kpi-meta">Actions avec besoin financier sur cette page</p>
        </article>
    </section>

    <section class="showcase-toolbar mb-4">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Filtres de navigation</h2>
                <p class="showcase-panel-subtitle">Affinez le listing par contenu, PTA, statut dynamique et besoin de financement.</p>
            </div>
            <a class="btn btn-secondary rounded-2xl px-4 py-2" href="{{ route('workspace.actions.index') }}">
                Reinitialiser
            </a>
        </div>
        <form method="GET" action="{{ route('workspace.actions.index') }}">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre, description, resultat">
                </div>
                <div>
                    <label for="pta_id">PTA</label>
                    <select id="pta_id" name="pta_id">
                        <option value="">Tous</option>
                        @foreach ($ptaOptions as $pta)
                            <option value="{{ $pta->id }}" @selected($filters['pta_id'] === $pta->id)>
                                #{{ $pta->id }} - {{ $pta->titre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut">Statut dynamique</label>
                    <select id="statut" name="statut">
                        <option value="">Tous</option>
                        @foreach ($statusOptions as $status)
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="financement_requis">Financement requis</label>
                    <select id="financement_requis" name="financement_requis">
                        <option value="">Tous</option>
                        <option value="1" @selected($filters['financement_requis'] === 1)>Oui</option>
                        <option value="0" @selected($filters['financement_requis'] === 0)>Non</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">
                    Appliquer les filtres
                </button>
                @if ($canWrite)
                    <a class="btn btn-green rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.create') }}">
                        Creer une action
                    </a>
                @endif
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des actions</h2>
                <p class="showcase-panel-subtitle">Vue complete des actions accessibles dans votre perimetre metier.</p>
            </div>
            <span class="showcase-chip">{{ $rows->total() }} lignes au total</span>
        </div>

        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Action</th>
                        <th>PTA</th>
                        <th>Responsable</th>
                        <th>Progression</th>
                        <th>Frequence</th>
                        <th>Periodes</th>
                        <th>Statut</th>
                        <th>KPI global</th>
                        <th>Financement</th>
                        <th>Operations</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $semainesTotal = (int) ($row->semaines_total ?? 0);
                            $semainesRenseignees = (int) ($row->semaines_renseignees ?? 0);
                            $kpiGlobal = $row->actionKpi?->kpi_global;
                            $statusClass = $statusStyles[$row->statut_dynamique ?: 'non_demarre'] ?? $statusStyles['non_demarre'];
                            $progressValue = max(0, min(100, (float) ($row->progression_reelle ?? 0)));
                            $progressColor = $progressValue >= 80 ? 'bg-emerald-500' : ($progressValue >= 50 ? 'bg-blue-500' : ($progressValue > 0 ? 'bg-amber-500' : 'bg-slate-400'));
                            $kpiColor = $kpiGlobal !== null
                                ? ((float) $kpiGlobal >= 80 ? 'text-emerald-600 dark:text-emerald-300' : ((float) $kpiGlobal >= 60 ? 'text-amber-600 dark:text-amber-300' : 'text-red-600 dark:text-red-300'))
                                : 'text-slate-400 dark:text-slate-500';
                        @endphp
                        <tr>
                            <td class="font-mono text-xs text-slate-500 dark:text-slate-400">ACT-{{ str_pad((string) $row->id, 3, '0', STR_PAD_LEFT) }}</td>
                            <td class="min-w-[260px]">
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->libelle }}</div>
                                <p class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $row->description ?: 'Aucune description renseignee.' }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">Type {{ $row->type_cible }}</span>
                                    @if ($row->date_echeance)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                            Echeance {{ \Illuminate\Support\Carbon::parse($row->date_echeance)->format('d/m/Y') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="min-w-[170px]">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ $row->pta?->titre ?? '-' }}</div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">PTA rattache a l action</p>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ $row->responsable?->name ?? '-' }}</div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row->responsable?->agent_matricule ?? $row->responsable?->email ?? '-' }}</p>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="mb-2 flex items-center justify-between gap-2 text-xs">
                                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format($progressValue, 1) }}%</span>
                                    <span class="text-slate-500 dark:text-slate-400">Theo {{ number_format((float) ($row->progression_theorique ?? 0), 1) }}%</span>
                                </div>
                                <div class="showcase-progress-track">
                                    <span class="showcase-progress-bar {{ $progressColor }}" style="width: {{ $progressValue }}%"></span>
                                </div>
                            </td>
                            <td class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $row->frequence_execution ?: 'hebdomadaire' }}</td>
                            <td>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $semainesRenseignees }}/{{ $semainesTotal }}</div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">periodes renseignees</p>
                            </td>
                            <td>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">
                                    {{ $row->statut_dynamique ?: 'non_demarre' }}
                                </span>
                            </td>
                            <td>
                                <div class="text-base font-semibold {{ $kpiColor }}">
                                    {{ $kpiGlobal !== null ? number_format((float) $kpiGlobal, 1) . '%' : '-' }}
                                </div>
                            </td>
                            <td>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $row->financement_requis ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' }}">
                                    {{ $row->financement_requis ? 'Oui' : 'Non' }}
                                </span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-blue btn-sm rounded-xl" href="{{ route('workspace.actions.suivi', $row) }}">Suivi</a>
                                    @if ($canWrite)
                                        <a class="btn btn-amber btn-sm rounded-xl" href="{{ route('workspace.actions.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.actions.destroy', $row) }}" onsubmit="return confirm('Supprimer cette action ?')">
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
                            <td colspan="11" class="py-8 text-center text-slate-500 dark:text-slate-400">Aucune action trouvee pour les filtres courants.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </section>
@endsection
