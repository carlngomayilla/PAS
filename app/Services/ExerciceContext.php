<?php

namespace App\Services;

use App\Models\Action;
use App\Models\Exercice;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Support\SchemaIntrospectionCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

class ExerciceContext
{
    public const SESSION_KEY = 'pas_exercice_filter';
    public const QUARTER_SESSION_KEY = 'pas_trimestre_filter';

    public function selectedYear(): ?int
    {
        $request = request();

        if ($request->query->has('exercice')) {
            $value = trim((string) $request->query('exercice'));
            if ($value === '' || $value === 'all') {
                if ($request->hasSession()) {
                    $request->session()->forget(self::SESSION_KEY);
                }

                return null;
            }

            if (preg_match('/^\d{4}$/', $value) === 1) {
                $year = (int) $value;
                if ($request->hasSession()) {
                    $request->session()->put(self::SESSION_KEY, $year);
                }

                return $year;
            }
        }

        $sessionValue = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (is_numeric($sessionValue) && preg_match('/^\d{4}$/', (string) $sessionValue) === 1) {
            return (int) $sessionValue;
        }

        $activeYear = $this->activeExerciseYear();

        return $activeYear ?? (int) now()->year;
    }

    public function selectedQuarter(): ?int
    {
        $request = request();

        if ($request->query->has('trimestre')) {
            $value = trim((string) $request->query('trimestre'));
            if ($value === '' || $value === 'all') {
                if ($request->hasSession()) {
                    $request->session()->forget(self::QUARTER_SESSION_KEY);
                }

                return null;
            }

            if (preg_match('/^[1-4]$/', $value) === 1) {
                $quarter = (int) $value;
                if ($request->hasSession()) {
                    $request->session()->put(self::QUARTER_SESSION_KEY, $quarter);
                }

                return $quarter;
            }
        }

        $sessionValue = $request->hasSession() ? $request->session()->get(self::QUARTER_SESSION_KEY) : null;
        if (is_numeric($sessionValue) && preg_match('/^[1-4]$/', (string) $sessionValue) === 1) {
            return (int) $sessionValue;
        }

        return null;
    }

    public function activeLabel(): string
    {
        $year = $this->selectedYear();
        $quarter = $this->selectedQuarter();
        $quarterLabel = $this->activeQuarterLabel();

        if ($year === null) {
            return $quarter !== null ? 'Tous ex. - '.$quarterLabel : 'Tous ex.';
        }

        return $quarter !== null ? 'Ex. '.$year.' - '.$quarterLabel : 'Ex. '.$year;
    }

    public function activeQuarterLabel(): string
    {
        $quarter = $this->selectedQuarter();

        return match ($quarter) {
            1 => 'T1',
            2 => 'T2',
            3 => 'T3',
            4 => 'T4',
            default => 'Tous trimestres',
        };
    }

    /**
     * Annees affichees dans le selecteur d'exercice de la navbar.
     *
     * Regle metier ANBG (2026-05-30) : on n'expose QUE les annees couvertes par
     * un PAS existant en BDD (periode_debut..periode_fin) plus celles deduites
     * des PAO presents. Aucune annee "fallback" (annee courante ±N) ni annee
     * issue de la table exercices pour eviter d'afficher des annees sans aucune
     * donnee planning. Quand l'appli est vide, le selecteur ne montre que "Tous ex.".
     *
     * @return list<array{value: string, label: string, statut: string|null}>
     */
    public function options(): array
    {
        $years = [];
        $statuses = [];

        // 1. Annees couvertes par les PAS en BDD (periode_debut → periode_fin inclus).
        if (SchemaIntrospectionCache::hasTable('pas')) {
            Pas::query()
                ->select(['periode_debut', 'periode_fin'])
                ->get()
                ->each(static function (Pas $pas) use (&$years): void {
                    $start = (int) $pas->periode_debut;
                    $end = (int) $pas->periode_fin;
                    if ($start <= 0 || $end <= 0) {
                        return;
                    }

                    foreach (range($start, $end) as $year) {
                        $years[$year] = 'Exercice '.$year;
                    }
                });
        }

        // 2. Annees des PAO (filet de securite si un PAO orphelin existe).
        if (SchemaIntrospectionCache::hasTable('paos')) {
            Pao::query()
                ->whereNotNull('annee')
                ->distinct()
                ->pluck('annee')
                ->each(static function ($year) use (&$years): void {
                    $year = (int) $year;
                    if ($year > 0) {
                        $years[$year] = $years[$year] ?? 'Exercice '.$year;
                    }
                });
        }

        // 3. Pour les annees retenues, on enrichit le libelle/statut depuis la
        //    table exercices SI un exercice correspondant existe. On NE remonte
        //    PAS d'annees supplementaires depuis cette table.
        if ($years !== [] && SchemaIntrospectionCache::hasTable('exercices')) {
            Exercice::query()
                ->whereIn('annee', array_keys($years))
                ->get(['annee', 'libelle', 'statut'])
                ->each(function (Exercice $exercice) use (&$years, &$statuses): void {
                    $year = (int) $exercice->annee;
                    if ($exercice->libelle !== null && $exercice->libelle !== '') {
                        $years[$year] = (string) $exercice->libelle;
                    }
                    $statuses[$year] = (string) $exercice->statut;
                });
        }

        krsort($years);

        $options = [[
            'value' => 'all',
            'label' => 'Tous ex.',
            'statut' => null,
        ]];

        foreach ($years as $year => $label) {
            $options[] = [
                'value' => (string) $year,
                'label' => (string) $label,
                'statut' => $statuses[(int) $year] ?? null,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function quarterOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Tous trim.'],
            ['value' => '1', 'label' => 'T1'],
            ['value' => '2', 'label' => 'T2'],
            ['value' => '3', 'label' => 'T3'],
            ['value' => '4', 'label' => 'T4'],
        ];
    }

    public function applyToPas(Builder|Relation $query, ?int $year = null): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->where('periode_debut', '<=', $year)
            ->where('periode_fin', '>=', $year);
    }

    public function applyToPao(Builder|Relation $query, ?int $year = null): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->where('annee', $year);

        if (($range = $this->quarterRange($year)) !== null) {
            $query->where(function (Builder $periodQuery) use ($range): void {
                $this->applyDateRangeToColumns($periodQuery, ['created_at', 'echeance'], $range);
                $periodQuery->orWhereHas('objectifsOperationnels', fn (Builder $objectiveQuery) => $objectiveQuery->whereBetween('echeance', $range))
                    ->orWhereHas('ptas.actions', function (Builder $actionQuery) use ($range): void {
                        $this->applyDateRangeToColumns($actionQuery, ['date_echeance', 'date_fin', 'date_debut', 'created_at'], $range);
                    });
            });
        }
    }

    public function applyToPta(Builder|Relation $query, ?int $year = null): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->whereHas('pao', fn (Builder $paoQuery) => $paoQuery->where('annee', $year));

        if (($range = $this->quarterRange($year)) !== null) {
            $query->where(function (Builder $periodQuery) use ($range): void {
                $periodQuery->whereBetween('created_at', $range)
                    ->orWhereHas('objectifOperationnel', fn (Builder $objectiveQuery) => $objectiveQuery->whereBetween('echeance', $range))
                    ->orWhereHas('actions', function (Builder $actionQuery) use ($range): void {
                        $this->applyDateRangeToColumns($actionQuery, ['date_echeance', 'date_fin', 'date_debut', 'created_at'], $range);
                    });
            });
        }
    }

    public function applyToAction(Builder|Relation $query, ?int $year = null): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->where(function (Builder $actionQuery) use ($year): void {
            $actionQuery->whereHas('pta.pao', fn (Builder $paoQuery) => $paoQuery->where('annee', $year))
                ->orWhere(function (Builder $dateQuery) use ($year): void {
                    $dateQuery->whereDoesntHave('pta.pao')
                        ->whereBetween('date_debut', [$year.'-01-01', $year.'-12-31']);
                });
        });

        if (($range = $this->quarterRange($year)) !== null) {
            $query->where(function (Builder $periodQuery) use ($range): void {
                $this->applyDateRangeToColumns($periodQuery, ['date_echeance', 'date_fin', 'date_debut', 'created_at'], $range);
                $periodQuery->orWhereHas('weeks', fn (Builder $weeksQuery) => $weeksQuery->whereBetween('date_debut', $range));
            });
        }
    }

    public function applyToKpi(Builder|Relation $query, ?int $year = null): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->whereHas('action', fn (Builder $actionQuery) => $this->applyToAction($actionQuery, $year));
    }

    public function applyToMesure(Builder|Relation $query, ?int $year = null): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->whereHas('kpi.action', fn (Builder $actionQuery) => $this->applyToAction($actionQuery, $year));
    }

    public function applyToJoinedPta(\Illuminate\Database\Query\Builder|Builder $query, ?int $year = null, string $ptaTable = 'ptas'): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->whereExists(function ($subQuery) use ($year, $ptaTable): void {
            $subQuery->selectRaw('1')
                ->from('paos')
                ->whereColumn('paos.id', $ptaTable.'.pao_id')
                ->where('paos.annee', $year);
        });
    }

    public function applyToActionWeeksJoin(\Illuminate\Database\Query\Builder|Builder $query, ?int $year = null): void
    {
        $year ??= $this->selectedYear();
        if ($year === null) {
            return;
        }

        $query->whereBetween('action_weeks.date_debut', [$year.'-01-01', $year.'-12-31']);

        if (($range = $this->quarterRange($year)) !== null) {
            $query->whereBetween('action_weeks.date_debut', $range);
        }
    }

    public function idForYear(int $year): ?int
    {
        $this->upsertYear($year);

        if (! SchemaIntrospectionCache::hasTable('exercices')) {
            return null;
        }

        $id = Exercice::query()->where('annee', $year)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function upsertYear(int $year): void
    {
        if (! SchemaIntrospectionCache::hasTable('exercices')) {
            return;
        }

        Exercice::query()->firstOrCreate(
            ['annee' => $year],
            [
                'libelle' => 'Exercice '.$year,
                'date_debut' => Carbon::create($year, 1, 1)->toDateString(),
                'date_fin' => Carbon::create($year, 12, 31)->toDateString(),
                'statut' => $year < now()->year ? Exercice::STATUT_ARCHIVE : Exercice::STATUT_OUVERT,
                'is_active' => ! Exercice::query()->where('is_active', true)->exists() && $year === now()->year,
            ]
        );
    }

    private function activeExerciseYear(): ?int
    {
        if (! SchemaIntrospectionCache::hasTable('exercices')) {
            return null;
        }

        $year = Exercice::query()
            ->where('is_active', true)
            ->orderByDesc('annee')
            ->value('annee');

        return $year !== null ? (int) $year : null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function quarterRange(?int $year = null, ?int $quarter = null): ?array
    {
        $year ??= $this->selectedYear();
        $quarter ??= $this->selectedQuarter();

        if ($year === null || $quarter === null) {
            return null;
        }

        $startMonth = (($quarter - 1) * 3) + 1;
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addMonths(2)->endOfMonth()->endOfDay();

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * @param list<string> $columns
     */
    private function applyQuarterToColumns(Builder|Relation $query, array $columns, int $year): void
    {
        if (($range = $this->quarterRange($year)) === null) {
            return;
        }

        $query->where(function (Builder $periodQuery) use ($columns, $range): void {
            $this->applyDateRangeToColumns($periodQuery, $columns, $range);
        });
    }

    /**
     * @param list<string> $columns
     * @param array{0: string, 1: string} $range
     */
    private function applyDateRangeToColumns(Builder|Relation $query, array $columns, array $range): void
    {
        foreach ($columns as $index => $column) {
            if ($index === 0) {
                $query->whereBetween($column, $range);
                continue;
            }

            $query->orWhereBetween($column, $range);
        }
    }
}
