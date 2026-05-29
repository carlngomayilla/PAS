<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Database\Eloquent\Builder;

class PlanningClosureReportService
{
    private const CLOSED_PLANNING_STATUSES = ['cloture', 'archive'];

    private const TERMINAL_FINANCING_STATUSES = [
        Action::FINANCEMENT_NON_REQUIS,
        Action::FINANCEMENT_VALIDE_DAF,
        Action::FINANCEMENT_REJETE_DAF,
        Action::FINANCEMENT_VALIDE_DG,
        Action::FINANCEMENT_REJETE_DG,
    ];

    /**
     * @return array<string, mixed>
     */
    public function forPas(Pas $pas): array
    {
        $actions = $this->actionsForPas($pas);

        return $this->report('pas', [
            $this->issue('paos_ouverts', 'PAO ouverts', $pas->paos()->whereNotIn('statut', self::CLOSED_PLANNING_STATUSES)->count()),
            $this->issue('ptas_ouverts', 'PTA ouverts', Pta::query()->whereHas('pao', fn (Builder $query) => $query->where('pas_id', $pas->id))->whereNotIn('statut', self::CLOSED_PLANNING_STATUSES)->count()),
            $this->issue('actions_en_cours', 'Actions en cours', (clone $actions)->whereNotIn('statut_dynamique', ActionTrackingService::completedActionStatuses())->count()),
            $this->issue('actions_en_retard', 'Actions en retard', $this->lateActions((clone $actions))->count()),
            $this->issue('validations_en_attente', 'Validations en attente', $this->pendingValidations((clone $actions))->count()),
            $this->issue('kpi_incomplets', 'KPI incomplets', $this->incompleteKpis((clone $actions))->count()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPao(Pao $pao): array
    {
        $actions = $this->actionsForPao($pao);

        return $this->report('pao', [
            $this->issue('ptas_non_clotures', 'PTA non clotures', $pao->ptas()->whereNotIn('statut', self::CLOSED_PLANNING_STATUSES)->count()),
            $this->issue('actions_en_cours', 'Actions en cours', (clone $actions)->whereNotIn('statut_dynamique', ActionTrackingService::completedActionStatuses())->count()),
            $this->issue('actions_en_attente_validation', 'Actions en attente validation', $this->pendingValidations((clone $actions))->count()),
            $this->issue('actions_en_retard', 'Actions en retard', $this->lateActions((clone $actions))->count()),
            $this->issue('financements_non_traites', 'Financements non traites', $this->unprocessedFunding((clone $actions))->count()),
            $this->issue('blocages_controle', 'Blocages SCIQ / Planification', $this->activeControlBlocks((clone $actions))),
            $this->issue('objectifs_sans_actions', 'Objectifs operationnels sans actions', $pao->objectifsOperationnels()->whereDoesntHave('actions')->count()),
            $this->issue('kpi_incomplets', 'KPI incomplets', $this->incompleteKpis((clone $actions))->count()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPta(Pta $pta): array
    {
        $actions = $this->actionsForPta($pta);

        return $this->report('pta', [
            $this->issue('actions_non_demarre', 'Actions non demarrees', (clone $actions)->where(function (Builder $query): void {
                $query->whereNull('statut_dynamique')
                    ->orWhere('statut_dynamique', ActionTrackingService::STATUS_NON_DEMARRE);
            })->count()),
            $this->issue('actions_en_cours', 'Actions en cours', (clone $actions)->whereIn('statut_dynamique', [ActionTrackingService::STATUS_EN_COURS, ActionTrackingService::STATUS_A_RISQUE])->count()),
            $this->issue('actions_en_attente_validation_chef', 'Actions en attente validation chef', $this->pendingValidations((clone $actions))->count()),
            $this->issue('actions_en_attente_directeur', 'Actions en attente directeur', (clone $actions)->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_CHEF)->where('financement_requis', true)->whereNotIn('financement_statut', self::TERMINAL_FINANCING_STATUSES)->count()),
            $this->issue('actions_en_retard', 'Actions en retard', $this->lateActions((clone $actions))->count()),
            $this->issue('actions_sous_cible', 'Actions sous cible', $this->underTarget((clone $actions))->count()),
            $this->issue('financements_non_traites', 'Financements non traites', $this->unprocessedFunding((clone $actions))->count()),
            $this->issue('blocages_controle', 'Blocages SCIQ / Planification', $this->activeControlBlocks((clone $actions))),
        ]);
    }

    /**
     * @param array<string, mixed> $report
     */
    public function hasAnomalies(array $report): bool
    {
        return (int) ($report['total'] ?? 0) > 0;
    }

    /**
     * @param list<array{code: string, label: string, count: int}> $issues
     * @return array<string, mixed>
     */
    private function report(string $module, array $issues): array
    {
        $activeIssues = collect($issues)
            ->filter(fn (array $issue): bool => (int) $issue['count'] > 0)
            ->values();

        return [
            'module' => $module,
            'total' => $activeIssues->sum('count'),
            'issues' => $activeIssues->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{code: string, label: string, count: int}
     */
    private function issue(string $code, string $label, int $count): array
    {
        return compact('code', 'label', 'count');
    }

    private function actionsForPas(Pas $pas): Builder
    {
        return Action::query()
            ->whereHas('pta.pao', fn (Builder $query) => $query->where('pas_id', $pas->id));
    }

    private function actionsForPao(Pao $pao): Builder
    {
        return Action::query()
            ->whereHas('pta', fn (Builder $query) => $query->where('pao_id', $pao->id));
    }

    private function actionsForPta(Pta $pta): Builder
    {
        return Action::query()->where('pta_id', $pta->id);
    }

    private function pendingValidations(Builder $query): Builder
    {
        return $query->whereIn('statut_validation', [
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        ]);
    }

    private function lateActions(Builder $query): Builder
    {
        return $query
            ->whereNotIn('statut_dynamique', ActionTrackingService::completedActionStatuses())
            ->where(function (Builder $dateQuery): void {
                $dateQuery
                    ->whereDate('date_fin', '<', now()->toDateString())
                    ->orWhereDate('date_echeance', '<', now()->toDateString());
            });
    }

    private function underTarget(Builder $query): Builder
    {
        return $query
            ->whereNotIn('statut_dynamique', ActionTrackingService::completedActionStatuses())
            ->where(function (Builder $targetQuery): void {
                $targetQuery
                    ->whereNull('progression_reelle')
                    ->orWhereColumn('progression_reelle', '<', 'seuil_minimum');
            });
    }

    private function unprocessedFunding(Builder $query): Builder
    {
        return $query
            ->where('financement_requis', true)
            ->whereNotIn('financement_statut', self::TERMINAL_FINANCING_STATUSES);
    }

    private function incompleteKpis(Builder $query): Builder
    {
        return $query->where(function (Builder $kpiQuery): void {
            $kpiQuery
                ->whereDoesntHave('actionKpi')
                ->orWhereHas('actionKpi', fn (Builder $actionKpiQuery) => $actionKpiQuery->whereNull('kpi_global'));
        });
    }

    private function activeControlBlocks(Builder $query): int
    {
        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return ActionLog::query()
            ->activeAlert()
            ->whereIn('action_id', $ids)
            ->count();
    }
}
