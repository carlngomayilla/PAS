<?php

namespace App\Services\Dashboard;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\DeadlineExtensionRequest;
use App\Models\Kpi;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class EssentialDashboardService
{
    /**
     * @return array{
     *     profile:string,
     *     label:string,
     *     max_cards:int,
     *     cards:list<array<string,mixed>>,
     *     alerts:list<array<string,string>>
     * }
     */
    public function forUser(User $user): array
    {
        $profile = $this->profileFor($user);
        $profileConfig = config("dashboard_profiles.{$profile}", config('dashboard_profiles.default'));
        $cardKeys = array_slice((array) ($profileConfig['cards'] ?? []), 0, (int) ($profileConfig['max_cards'] ?? 3));
        $actions = $this->scopedActions($user);
        $stats = array_merge($this->stats($actions), $this->dataQualityStats($user));

        return [
            'profile' => $profile,
            'label' => $this->profileLabel($profile),
            'max_cards' => (int) ($profileConfig['max_cards'] ?? 3),
            'cards' => collect($cardKeys)
                ->map(fn (string $key): array => $this->card($key, $stats))
                ->values()
                ->all(),
            'alerts' => $this->alerts($actions),
        ];
    }

    private function profileFor(User $user): string
    {
        if ($user->hasRole(User::ROLE_DG, User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_FONCTIONNEL)) {
            return 'dg';
        }

        if ($user->hasRole(User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL)
            || $user->isPlanningControlChief()
            || $user->hasRole(User::ROLE_AUDITEUR, User::ROLE_INVITE_LECTURE)
        ) {
            return 'suivi_evaluation';
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return 'direction';
        }

        if ($user->isServiceOrUnitChief()) {
            return 'service';
        }

        if ($user->isAgent()) {
            return 'agent';
        }

        return 'default';
    }

    private function profileLabel(string $profile): string
    {
        return match ($profile) {
            'dg' => 'Vue DG',
            'direction' => 'Vue direction',
            'service' => 'Vue service',
            'agent' => 'Vue agent',
            'planification' => 'Vue planification',
            'suivi_evaluation' => 'Vue suivi-evaluation',
            default => 'Vue essentielle',
        };
    }

    /**
     * @return Builder<Action>
     */
    private function scopedActions(User $user): Builder
    {
        $query = Action::query()->whereHas('pta');

        if ($user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL)
            || $user->isPlanningControlChief()
        ) {
            return $query;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return $query->whereHas('pta', fn (Builder $ptaQuery): Builder => $ptaQuery->where('direction_id', $user->direction_id));
        }

        if (($user->isServiceOrUnitChief() || $user->hasRole(User::ROLE_SERVICE)) && $user->service_id !== null) {
            return $query->whereHas('pta', fn (Builder $ptaQuery): Builder => $ptaQuery
                ->where('direction_id', $user->direction_id)
                ->where('service_id', $user->service_id));
        }

        if ($user->isAgent()) {
            return $query->where(function (Builder $agentQuery) use ($user): void {
                $agentQuery
                    ->where('responsable_id', $user->id)
                    ->orWhereHas('responsables', fn (Builder $responsableQuery): Builder => $responsableQuery->whereKey($user->id))
                    ->orWhereHas('sousActions', fn (Builder $subActionQuery): Builder => $subActionQuery->where('agent_id', $user->id));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<Action>  $actions
     * @return array<string, int|float>
     */
    private function stats(Builder $actions): array
    {
        $today = Carbon::today()->toDateString();
        $soon = Carbon::today()->addDays(14)->toDateString();

        $total = (clone $actions)->count();
        $completed = (clone $actions)->where('progression_reelle', '>=', 100)->count();
        $late = (clone $actions)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->where(fn (Builder $query): Builder => $query->whereNull('progression_reelle')->orWhere('progression_reelle', '<', 100))
            ->count();
        $dueSoon = (clone $actions)
            ->whereNotNull('date_echeance')
            ->whereBetween('date_echeance', [$today, $soon])
            ->where(fn (Builder $query): Builder => $query->whereNull('progression_reelle')->orWhere('progression_reelle', '<', 100))
            ->count();
        $waiting = (clone $actions)
            ->whereIn('statut_validation', [
                'soumise_chef',
                'soumise_controle',
                'soumise_direction',
                'correction_demandee',
                'correction_controle',
            ])
            ->count();
        $activeDeadlineExtensions = DeadlineExtensionRequest::query()
            ->whereIn('status', [
                DeadlineExtensionRequest::STATUS_SOUMISE,
                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
                DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION,
                DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
                DeadlineExtensionRequest::STATUS_APPROUVEE,
            ])
            ->whereIn('action_id', (clone $actions)->select('actions.id'))
            ->count();
        $criticalAlerts = ActionLog::query()
            ->whereIn('action_id', (clone $actions)->select('actions.id'))
            ->activeAlert()
            ->count();
        $averageProgress = (float) ((clone $actions)->avg('progression_reelle') ?? 0);

        return [
            'total' => $total,
            'completed' => $completed,
            'late' => $late,
            'due_soon' => $dueSoon,
            'waiting' => $waiting,
            'deadline_extensions' => $activeDeadlineExtensions,
            'critical_alerts' => $criticalAlerts,
            'average_progress' => round($averageProgress, 1),
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
        ];
    }

    /**
     * @param  array<string, int|float>  $stats
     * @return array<string, mixed>
     */
    private function card(string $key, array $stats): array
    {
        return match ($key) {
            'strategic_progress', 'direction_progress', 'service_progress', 'global_execution' => [
                'key' => $key,
                'label' => 'Execution moyenne',
                'value' => number_format((float) $stats['average_progress'], 1).'%',
                'caption' => number_format((float) $stats['completion_rate'], 1).'% actions terminees',
                'tone' => 'accent',
                'href' => route('workspace.reporting'),
            ],
            'late_actions', 'services_late' => [
                'key' => $key,
                'label' => 'Actions en retard',
                'value' => (int) $stats['late'],
                'caption' => 'Echeance depassee et progression incomplete',
                'tone' => ((int) $stats['late']) > 0 ? 'danger' : 'success',
                'href' => route('workspace.actions.index'),
            ],
            'validation_waiting', 'pta_to_validate', 'actions_to_review', 'pta_to_control' => [
                'key' => $key,
                'label' => 'A valider',
                'value' => (int) $stats['waiting'],
                'caption' => 'Dossiers en attente de revue',
                'tone' => ((int) $stats['waiting']) > 0 ? 'warning' : 'success',
                'href' => route('workspace.actions.index'),
            ],
            'critical_alerts', 'agent_blockers', 'evidence_gaps', 'audit_events' => [
                'key' => $key,
                'label' => 'Alertes actives',
                'value' => (int) $stats['critical_alerts'],
                'caption' => 'Alertes non resolues',
                'tone' => ((int) $stats['critical_alerts']) > 0 ? 'danger' : 'success',
                'href' => route('workspace.alertes'),
            ],
            'data_quality' => [
                'key' => $key,
                'label' => 'Qualite des donnees',
                'value' => (int) $stats['data_quality_issues'],
                'caption' => (string) $stats['data_quality_caption'],
                'tone' => ((int) $stats['data_quality_issues']) > 0 ? 'warning' : 'success',
                'href' => route('workspace.pilotage'),
            ],
            'deadline_extensions' => [
                'key' => $key,
                'label' => 'Reports echeance',
                'value' => (int) $stats['deadline_extensions'],
                'caption' => 'Demandes en cours',
                'tone' => ((int) $stats['deadline_extensions']) > 0 ? 'warning' : 'success',
                'href' => route('workspace.actions.index'),
            ],
            'assigned_actions' => [
                'key' => $key,
                'label' => 'Actions assignees',
                'value' => (int) $stats['total'],
                'caption' => 'Dans votre perimetre',
                'tone' => 'accent',
                'href' => route('workspace.actions.index'),
            ],
            'due_soon' => [
                'key' => $key,
                'label' => 'A echeance proche',
                'value' => (int) $stats['due_soon'],
                'caption' => 'Dans les 14 prochains jours',
                'tone' => ((int) $stats['due_soon']) > 0 ? 'warning' : 'success',
                'href' => route('workspace.actions.index'),
            ],
            'corrections_requested' => [
                'key' => $key,
                'label' => 'Corrections',
                'value' => (int) $stats['waiting'],
                'caption' => 'Retours et corrections demandees',
                'tone' => ((int) $stats['waiting']) > 0 ? 'warning' : 'success',
                'href' => route('workspace.actions.index'),
            ],
            default => [
                'key' => $key,
                'label' => 'Actions',
                'value' => (int) $stats['total'],
                'caption' => 'Perimetre courant',
                'tone' => 'info',
                'href' => route('workspace.actions.index'),
            ],
        };
    }

    /**
     * @return array{data_quality_issues:int,data_quality_caption:string}
     */
    private function dataQualityStats(User $user): array
    {
        $paosWithoutPta = (clone $this->scopedPaos($user))
            ->doesntHave('ptas')
            ->count();
        $ptasWithoutService = (clone $this->scopedPtas($user))
            ->whereNull('service_id')
            ->count();
        $ptasWithoutObjective = (clone $this->scopedPtas($user))
            ->whereNull('objectif_operationnel_id')
            ->count();
        $actionsWithoutObjective = (clone $this->scopedQualityActions($user))
            ->whereNull('objectif_operationnel_id')
            ->count();
        $actionsWithoutRecentUpdate = (clone $this->scopedQualityActions($user))
            ->where('updated_at', '<', Carbon::today()->subDays(30))
            ->whereDoesntHave('actionLogs')
            ->count();
        $indicatorsWithoutSource = (clone $this->scopedKpis($user))
            ->where('est_a_renseigner', true)
            ->whereDoesntHave('mesures')
            ->whereDoesntHave('justificatifs')
            ->count();
        $completedActionsWithoutProof = (clone $this->scopedQualityActions($user))
            ->where('progression_reelle', '>=', 100)
            ->whereDoesntHave('justificatifs')
            ->whereDoesntHave('sousActions.justificatifs')
            ->count();
        $actionsPaoMismatch = (clone $this->scopedQualityActions($user))
            ->whereNotNull('pao_id')
            ->whereHas('pta', fn (Builder $query): Builder => $query->whereColumn('ptas.pao_id', '!=', 'actions.pao_id'))
            ->count();
        $actionObjectivePaoMismatch = (clone $this->scopedQualityActions($user))
            ->whereNotNull('objectif_operationnel_id')
            ->whereHas('objectifOperationnel', fn (Builder $query): Builder => $query->whereColumn('objectifs_operationnels.pao_id', '!=', 'actions.pao_id'))
            ->count();
        $ptaObjectivePaoMismatch = (clone $this->scopedPtas($user))
            ->whereNotNull('objectif_operationnel_id')
            ->whereHas('objectifOperationnel', fn (Builder $query): Builder => $query->whereColumn('objectifs_operationnels.pao_id', '!=', 'ptas.pao_id'))
            ->count();
        $paoPasMismatch = (clone $this->scopedPaos($user))
            ->whereNotNull('pas_objectif_id')
            ->whereHas('pasObjectif.pasAxe', fn (Builder $query): Builder => $query->whereColumn('pas_axes.pas_id', '!=', 'paos.pas_id'))
            ->count();
        $hierarchyInconsistencies = $actionsPaoMismatch
            + $actionObjectivePaoMismatch
            + $ptaObjectivePaoMismatch
            + $paoPasMismatch;

        $total = $paosWithoutPta
            + $ptasWithoutService
            + $ptasWithoutObjective
            + $actionsWithoutObjective
            + $actionsWithoutRecentUpdate
            + $indicatorsWithoutSource
            + $completedActionsWithoutProof
            + $hierarchyInconsistencies;

        $parts = array_filter([
            $paosWithoutPta > 0 ? $paosWithoutPta.' PAO sans PTA' : null,
            $ptasWithoutService > 0 ? $ptasWithoutService.' PTA sans service' : null,
            $ptasWithoutObjective > 0 ? $ptasWithoutObjective.' PTA sans objectif' : null,
            $actionsWithoutObjective > 0 ? $actionsWithoutObjective.' action(s) sans objectif' : null,
            $actionsWithoutRecentUpdate > 0 ? $actionsWithoutRecentUpdate.' action(s) sans mise a jour recente' : null,
            $indicatorsWithoutSource > 0 ? $indicatorsWithoutSource.' indicateur(s) sans source' : null,
            $completedActionsWithoutProof > 0 ? $completedActionsWithoutProof.' action(s) a 100% sans justificatif' : null,
            $hierarchyInconsistencies > 0 ? $hierarchyInconsistencies.' incoherence(s) PAS/PAO/PTA' : null,
        ]);

        return [
            'data_quality_issues' => $total,
            'data_quality_caption' => $total > 0 ? implode(', ', $parts) : 'Coherence PAS/PAO/PTA OK',
        ];
    }

    /**
     * @return Builder<Pao>
     */
    private function scopedPaos(User $user): Builder
    {
        $query = Pao::query();

        if ($user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL)
            || $user->isPlanningControlChief()
        ) {
            return $query;
        }

        if ($user->direction_id !== null) {
            $query->where('direction_id', $user->direction_id);
        }

        if ($user->service_id !== null && ! $user->hasRole(User::ROLE_DIRECTION)) {
            $query->where(function (Builder $serviceQuery) use ($user): void {
                $serviceQuery->where('service_id', $user->service_id)
                    ->orWhereNull('service_id');
            });
        }

        return $query;
    }

    /**
     * @return Builder<Pta>
     */
    private function scopedPtas(User $user): Builder
    {
        $query = Pta::query();

        if ($user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL)
            || $user->isPlanningControlChief()
        ) {
            return $query;
        }

        if ($user->direction_id !== null) {
            $query->where('direction_id', $user->direction_id);
        }

        if ($user->service_id !== null && ! $user->hasRole(User::ROLE_DIRECTION)) {
            $query->where('service_id', $user->service_id);
        }

        return $query;
    }

    /**
     * @return Builder<Action>
     */
    private function scopedQualityActions(User $user): Builder
    {
        $query = Action::query();

        if ($user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL)
            || $user->isPlanningControlChief()
        ) {
            return $query;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return $query->whereHas('pta', fn (Builder $ptaQuery): Builder => $ptaQuery->where('direction_id', $user->direction_id));
        }

        if (($user->isServiceOrUnitChief() || $user->hasRole(User::ROLE_SERVICE)) && $user->service_id !== null) {
            return $query->whereHas('pta', fn (Builder $ptaQuery): Builder => $ptaQuery
                ->where('direction_id', $user->direction_id)
                ->where('service_id', $user->service_id));
        }

        if ($user->isAgent()) {
            return $query->where(function (Builder $agentQuery) use ($user): void {
                $agentQuery
                    ->where('responsable_id', $user->id)
                    ->orWhereHas('responsables', fn (Builder $responsableQuery): Builder => $responsableQuery->whereKey($user->id))
                    ->orWhereHas('sousActions', fn (Builder $subActionQuery): Builder => $subActionQuery->where('agent_id', $user->id));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @return Builder<Kpi>
     */
    private function scopedKpis(User $user): Builder
    {
        $query = Kpi::query();

        if ($user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL)
            || $user->isPlanningControlChief()
        ) {
            return $query;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return $query->whereHas('action.pta', fn (Builder $ptaQuery): Builder => $ptaQuery->where('direction_id', $user->direction_id));
        }

        if (($user->isServiceOrUnitChief() || $user->hasRole(User::ROLE_SERVICE)) && $user->service_id !== null) {
            return $query->whereHas('action.pta', fn (Builder $ptaQuery): Builder => $ptaQuery
                ->where('direction_id', $user->direction_id)
                ->where('service_id', $user->service_id));
        }

        if ($user->isAgent()) {
            return $query->whereHas('action', function (Builder $actionQuery) use ($user): void {
                $actionQuery
                    ->where('responsable_id', $user->id)
                    ->orWhereHas('responsables', fn (Builder $responsableQuery): Builder => $responsableQuery->whereKey($user->id))
                    ->orWhereHas('sousActions', fn (Builder $subActionQuery): Builder => $subActionQuery->where('agent_id', $user->id));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<Action>  $actions
     * @return list<array<string,string>>
     */
    private function alerts(Builder $actions): array
    {
        return ActionLog::query()
            ->with('action:id,libelle')
            ->whereIn('action_id', (clone $actions)->select('actions.id'))
            ->activeAlert()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (ActionLog $log): array => [
                'title' => (string) ($log->action?->libelle ?? 'Action'),
                'message' => (string) $log->message,
                'status' => in_array((string) $log->niveau, ['critical', 'urgence'], true) ? 'danger' : 'warning',
                'label' => (string) ($log->niveau ?: 'alerte'),
            ])
            ->values()
            ->all();
    }
}
