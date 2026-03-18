<?php

namespace App\Services\Alerting;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\KpiMesure;
use App\Models\PasObjectif;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AlertCenterService
{
    use AuthorizesPlanningScope;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function buildForUser(User $user, int $limit = 20): Collection
    {
        $limit = max(1, min(100, $limit));

        $items = collect()
            ->merge($this->buildOverdueActionItems($user, $limit))
            ->merge($this->buildKpiItems($user, $limit))
            ->merge($this->buildActionLogItems($user, $limit))
            ->merge($this->buildMissingPaoCoverageItems($user, $limit))
            ->merge($this->buildDelegationItems($user, $limit));

        return $items
            ->sortByDesc(static fn (array $item): int => (int) ($item['sort_timestamp'] ?? 0))
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(User $user, string $type, int $id): ?array
    {
        return match ($type) {
            'action_overdue' => $this->findOverdueActionItem($user, $id),
            'kpi_breach' => $this->findKpiItem($user, $id),
            'action_log' => $this->findActionLogItem($user, $id),
            'missing_pao_coverage' => $this->findMissingPaoCoverageItem($user, $id),
            'delegation_expiring' => $this->findDelegationItem($user, $id),
            default => null,
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildOverdueActionItems(User $user, int $limit): Collection
    {
        $today = Carbon::today()->toDateString();

        $query = Action::query()
            ->with([
                'pta:id,direction_id,service_id,titre',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'responsable:id,name',
            ])
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->whereNotIn('statut_dynamique', ['acheve_dans_delai', 'acheve_hors_delai']);

        $this->scopeAction($query, $user);

        return $query
            ->orderBy('date_echeance')
            ->limit($limit)
            ->get()
            ->map(fn (Action $action): array => $this->mapOverdueAction($action));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildKpiItems(User $user, int $limit): Collection
    {
        $query = KpiMesure::query()
            ->select('kpi_mesures.id')
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');

        $this->scopeJoinedPta($query, $user, 'ptas.direction_id', 'ptas.service_id');

        $ids = $query
            ->orderByDesc('kpi_mesures.id')
            ->limit($limit)
            ->pluck('kpi_mesures.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return collect();
        }

        return KpiMesure::query()
            ->with([
                'kpi:id,action_id,libelle,seuil_alerte,periodicite',
                'kpi.action:id,pta_id,libelle,responsable_id',
                'kpi.action.pta:id,direction_id,service_id,titre',
                'kpi.action.pta.direction:id,code,libelle',
                'kpi.action.pta.service:id,code,libelle',
            ])
            ->whereIn('id', $ids)
            ->orderByDesc('id')
            ->get()
            ->map(fn (KpiMesure $mesure): array => $this->mapKpiMesure($mesure));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildActionLogItems(User $user, int $limit): Collection
    {
        $query = ActionLog::query()
            ->with([
                'action:id,pta_id,libelle,statut_dynamique',
                'action.pta:id,direction_id,service_id,titre',
                'action.pta.direction:id,code,libelle',
                'action.pta.service:id,code,libelle',
                'week:id,action_id,numero_semaine',
                'utilisateur:id,name',
            ])
            ->whereIn('niveau', ['warning', 'critical']);

        if (! $user->hasGlobalReadAccess()) {
            $query->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
            });
        }

        return $query
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ActionLog $log): array => $this->mapActionLog($log));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildDelegationItems(User $user, int $limit): Collection
    {
        $moduleCodes = collect($user->workspaceModules())->pluck('code');
        if (! $moduleCodes->contains('delegations')) {
            return collect();
        }

        $deadline = Carbon::today()->addDays(3)->endOfDay();
        $query = Delegation::query()
            ->with([
                'delegant:id,name',
                'delegue:id,name',
                'direction:id,code,libelle',
                'service:id,code,libelle',
            ])
            ->active()
            ->whereNotNull('date_fin')
            ->where('date_fin', '<=', $deadline)
            ->orderBy('date_fin')
            ->limit($limit);

        return $query
            ->get()
            ->map(fn (Delegation $delegation): array => $this->mapDelegation($delegation));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildMissingPaoCoverageItems(User $user, int $limit): Collection
    {
        if (! $user->hasGlobalReadAccess() && ! $user->hasRole(User::ROLE_DIRECTION)) {
            return collect();
        }

        $directionIds = $this->scopedDirectionIds($user);
        if ($directionIds === []) {
            return collect();
        }

        return PasObjectif::query()
            ->with([
                'pasAxe:id,pas_id,code,libelle',
                'pasAxe.pas:id,titre,periode_debut,periode_fin',
                'paos:id,pas_objectif_id,direction_id',
            ])
            ->whereHas('pasAxe.pas.directions', fn (Builder $query) => $query->whereIn('directions.id', $directionIds))
            ->orderBy('pas_axe_id')
            ->orderBy('ordre')
            ->orderBy('id')
            ->get()
            ->flatMap(function (PasObjectif $objectif) use ($directionIds): array {
                $coveredDirectionIds = $objectif->paos
                    ->pluck('direction_id')
                    ->filter()
                    ->map(static fn ($value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all();
                $missingDirectionIds = array_values(array_diff($directionIds, $coveredDirectionIds));
                if ($missingDirectionIds === []) {
                    return [];
                }

                return Direction::query()
                    ->whereIn('id', $missingDirectionIds)
                    ->orderBy('code')
                    ->get(['id', 'code', 'libelle'])
                    ->map(fn (Direction $direction): array => $this->mapMissingPaoCoverage($objectif, $direction))
                    ->all();
            })
            ->take($limit)
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOverdueActionItem(User $user, int $id): ?array
    {
        $today = Carbon::today()->toDateString();

        $query = Action::query()
            ->with([
                'pta:id,direction_id,service_id,titre',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'responsable:id,name',
            ])
            ->whereKey($id)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->whereNotIn('statut_dynamique', ['acheve_dans_delai', 'acheve_hors_delai']);

        $this->scopeAction($query, $user);

        $action = $query->first();

        return $action instanceof Action ? $this->mapOverdueAction($action) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findKpiItem(User $user, int $id): ?array
    {
        $candidateIds = KpiMesure::query()
            ->select('kpi_mesures.id')
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->where('kpi_mesures.id', $id)
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');

        $this->scopeJoinedPta($candidateIds, $user, 'ptas.direction_id', 'ptas.service_id');

        if (! $candidateIds->exists()) {
            return null;
        }

        $mesure = KpiMesure::query()
            ->with([
                'kpi:id,action_id,libelle,seuil_alerte,periodicite',
                'kpi.action:id,pta_id,libelle,responsable_id',
                'kpi.action.pta:id,direction_id,service_id,titre',
                'kpi.action.pta.direction:id,code,libelle',
                'kpi.action.pta.service:id,code,libelle',
            ])
            ->find($id);

        return $mesure instanceof KpiMesure ? $this->mapKpiMesure($mesure) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActionLogItem(User $user, int $id): ?array
    {
        $query = ActionLog::query()
            ->with([
                'action:id,pta_id,libelle,statut_dynamique',
                'action.pta:id,direction_id,service_id,titre',
                'action.pta.direction:id,code,libelle',
                'action.pta.service:id,code,libelle',
                'week:id,action_id,numero_semaine',
                'utilisateur:id,name',
            ])
            ->whereKey($id)
            ->whereIn('niveau', ['warning', 'critical']);

        if (! $user->hasGlobalReadAccess()) {
            $query->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
            });
        }

        $log = $query->first();

        return $log instanceof ActionLog ? $this->mapActionLog($log) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDelegationItem(User $user, int $id): ?array
    {
        $moduleCodes = collect($user->workspaceModules())->pluck('code');
        if (! $moduleCodes->contains('delegations')) {
            return null;
        }

        $delegation = Delegation::query()
            ->with([
                'delegant:id,name',
                'delegue:id,name',
                'direction:id,code,libelle',
                'service:id,code,libelle',
            ])
            ->active()
            ->whereKey($id)
            ->first();

        return $delegation instanceof Delegation ? $this->mapDelegation($delegation) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMissingPaoCoverageItem(User $user, int $id): ?array
    {
        if (! $user->hasGlobalReadAccess() && ! $user->hasRole(User::ROLE_DIRECTION)) {
            return null;
        }

        $directionIds = $this->scopedDirectionIds($user);
        if ($directionIds === []) {
            return null;
        }

        [$objectifId, $directionId] = $this->decodeCoverageIdentifier($id);
        if ($objectifId <= 0 || $directionId <= 0) {
            return null;
        }

        $objectif = PasObjectif::query()
            ->with([
                'pasAxe:id,pas_id,code,libelle',
                'pasAxe.pas:id,titre,periode_debut,periode_fin',
                'paos:id,pas_objectif_id,direction_id',
            ])
            ->find($objectifId);

        if (! $objectif instanceof PasObjectif) {
            return null;
        }

        $coveredDirectionIds = $objectif->paos
            ->pluck('direction_id')
            ->filter()
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
        $missingDirectionIds = array_values(array_diff($directionIds, $coveredDirectionIds));
        if (! in_array($directionId, $missingDirectionIds, true)) {
            return null;
        }

        $direction = Direction::query()->find($directionId, ['id', 'code', 'libelle']);

        return $direction instanceof Direction
            ? $this->mapMissingPaoCoverage($objectif, $direction)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOverdueAction(Action $action): array
    {
        $deadline = $action->date_echeance ? Carbon::parse($action->date_echeance) : Carbon::today();
        $daysLate = max(0, $deadline->diffInDays(Carbon::today()));
        $progression = (float) ($action->progression_reelle ?? 0);
        $isNotStarted = $progression <= 0.0;
        $level = $daysLate >= 7 || $isNotStarted ? 'critical' : 'warning';
        $type = $isNotStarted ? 'action_non_demarre' : 'retard';
        $title = $isNotStarted ? 'Action non demarree - delai depasse' : 'Action hors delai';
        $message = $isNotStarted
            ? sprintf('L action "%s" devait demarrer le %s et aucune progression n a ete enregistree.', (string) $action->libelle, $deadline->format('d/m/Y'))
            : sprintf('L action "%s" depasse l echeance de %d jour(s).', (string) $action->libelle, $daysLate);

        return [
            'source_type' => 'action_overdue',
            'source_id' => (int) $action->id,
            'module' => 'actions',
            'niveau' => $level,
            'niveau_label' => $this->levelLabel($level),
            'type' => $type,
            'type_label' => $this->typeLabel($type),
            'titre' => $title,
            'message' => $message,
            'date' => $deadline->toIso8601String(),
            'date_label' => $deadline->format('d/m/Y'),
            'sort_timestamp' => $deadline->timestamp,
            'direction' => $this->directionLabel($action->pta?->direction),
            'service' => $this->serviceLabel($action->pta?->service),
            'action' => [
                'id' => (int) $action->id,
                'libelle' => (string) $action->libelle,
                'pta' => (string) ($action->pta?->titre ?? '-'),
            ],
            'section_label' => 'Statut et avancement',
            'target_url' => route('workspace.actions.suivi', $action).'#action-status',
            'fingerprint' => 'action_overdue:'.$action->id.':'.$deadline->format('Ymd').':'.$type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapKpiMesure(KpiMesure $mesure): array
    {
        $action = $mesure->kpi?->action;
        $threshold = (float) ($mesure->kpi?->seuil_alerte ?? 0);
        $value = (float) $mesure->valeur;
        $isCritical = $threshold > 0 && $value <= ($threshold * 0.8);
        $level = $isCritical ? 'critical' : 'warning';
        $isGlobal = str_contains(mb_strtolower((string) ($mesure->kpi?->libelle ?? '')), 'global');
        $title = $isGlobal ? 'KPI global critique' : 'KPI sous seuil';
        $message = sprintf(
            '%s est a %.2f pour la periode %s, sous le seuil de %.2f.',
            (string) ($mesure->kpi?->libelle ?? 'Le KPI'),
            $value,
            (string) ($mesure->periode ?: '-'),
            $threshold
        );

        return [
            'source_type' => 'kpi_breach',
            'source_id' => (int) $mesure->id,
            'module' => 'actions',
            'niveau' => $level,
            'niveau_label' => $this->levelLabel($level),
            'type' => $isGlobal ? 'kpi_global' : 'kpi_sous_seuil',
            'type_label' => $this->typeLabel($isGlobal ? 'kpi_global' : 'kpi_sous_seuil'),
            'titre' => $title,
            'message' => $message,
            'date' => optional($mesure->created_at)?->toIso8601String() ?? now()->toIso8601String(),
            'date_label' => optional($mesure->created_at)->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
            'sort_timestamp' => optional($mesure->created_at)->timestamp ?? now()->timestamp,
            'direction' => $this->directionLabel($action?->pta?->direction),
            'service' => $this->serviceLabel($action?->pta?->service),
            'action' => $action instanceof Action ? [
                'id' => (int) $action->id,
                'libelle' => (string) $action->libelle,
                'pta' => (string) ($action->pta?->titre ?? '-'),
            ] : null,
            'section_label' => 'KPI et performance',
            'target_url' => $action instanceof Action ? route('workspace.actions.suivi', $action).'#action-status' : route('workspace.alertes'),
            'fingerprint' => 'kpi_breach:'.$mesure->id.':'.number_format($value, 4, '.', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapActionLog(ActionLog $log): array
    {
        $action = $log->action;
        $sectionLabel = $log->week !== null
            ? 'Suivi '.$this->typeLabel('periode_manquante')
            : 'Journal et validation';
        $targetUrl = $action instanceof Action
            ? route('workspace.actions.suivi', $action).($log->week !== null ? '#action-week-'.$log->week->id : '#action-logs')
            : route('workspace.alertes');

        return [
            'source_type' => 'action_log',
            'source_id' => (int) $log->id,
            'module' => 'actions',
            'niveau' => (string) $log->niveau,
            'niveau_label' => $this->levelLabel((string) $log->niveau),
            'type' => (string) $log->type_evenement,
            'type_label' => $this->typeLabel((string) $log->type_evenement),
            'titre' => $this->logTitle($log),
            'message' => (string) $log->message,
            'date' => optional($log->created_at)?->toIso8601String() ?? now()->toIso8601String(),
            'date_label' => optional($log->created_at)->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
            'sort_timestamp' => optional($log->created_at)->timestamp ?? now()->timestamp,
            'direction' => $this->directionLabel($action?->pta?->direction),
            'service' => $this->serviceLabel($action?->pta?->service),
            'action' => $action instanceof Action ? [
                'id' => (int) $action->id,
                'libelle' => (string) $action->libelle,
                'pta' => (string) ($action->pta?->titre ?? '-'),
            ] : null,
            'section_label' => $sectionLabel,
            'target_url' => $targetUrl,
            'fingerprint' => 'action_log:'.$log->id.':'.(optional($log->created_at)?->timestamp ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDelegation(Delegation $delegation): array
    {
        $endDate = $delegation->date_fin?->copy() ?? now();
        $daysLeft = max(0, Carbon::today()->diffInDays($endDate, false));
        $level = $daysLeft <= 1 ? 'warning' : 'info';
        $permissions = $delegation->permissionsCollection()->implode(', ');

        return [
            'source_type' => 'delegation_expiring',
            'source_id' => (int) $delegation->id,
            'module' => 'delegations',
            'niveau' => $level,
            'niveau_label' => $this->levelLabel($level),
            'type' => 'delegation_expiration',
            'type_label' => $this->typeLabel('delegation_expiration'),
            'titre' => 'Delegation expirant bientot',
            'message' => sprintf(
                'La delegation de %s vers %s expire le %s%s.',
                (string) ($delegation->delegant?->name ?? 'Utilisateur'),
                (string) ($delegation->delegue?->name ?? 'Utilisateur'),
                $endDate->format('d/m/Y'),
                $permissions !== '' ? ' ('.$permissions.')' : ''
            ),
            'date' => $endDate->toIso8601String(),
            'date_label' => $endDate->format('d/m/Y'),
            'sort_timestamp' => $endDate->timestamp,
            'direction' => $this->directionLabel($delegation->direction),
            'service' => $this->serviceLabel($delegation->service),
            'action' => null,
            'section_label' => 'Delegations',
            'target_url' => route('workspace.delegations.index'),
            'fingerprint' => 'delegation_expiring:'.$delegation->id.':'.$endDate->format('Ymd'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMissingPaoCoverage(PasObjectif $objectif, Direction $direction): array
    {
        $pas = $objectif->pasAxe?->pas;
        $now = now();

        return [
            'source_type' => 'missing_pao_coverage',
            'source_id' => $this->encodeCoverageIdentifier((int) $objectif->id, (int) $direction->id),
            'module' => 'pao',
            'niveau' => 'warning',
            'niveau_label' => $this->levelLabel('warning'),
            'type' => 'pao_manquant',
            'type_label' => $this->typeLabel('pao_manquant'),
            'titre' => 'Direction sans PAO sur OS',
            'message' => sprintf(
                'La direction %s n a pas encore de PAO pour l objectif strategique "%s" (%s).',
                $this->directionLabel($direction),
                (string) $objectif->libelle,
                (string) $objectif->code
            ),
            'date' => $now->toIso8601String(),
            'date_label' => $now->format('d/m/Y H:i'),
            'sort_timestamp' => $now->timestamp,
            'direction' => $this->directionLabel($direction),
            'service' => 'Couverture strategique',
            'action' => [
                'id' => (int) $objectif->id,
                'libelle' => (string) ($objectif->pasAxe?->code.' / '.$objectif->code.' - '.$objectif->libelle),
                'pta' => (string) ($pas?->titre ?? '-'),
            ],
            'section_label' => 'Couverture PAO',
            'target_url' => route('workspace.pao.index', [
                'pas_id' => (int) ($objectif->pasAxe?->pas_id ?? 0),
                'pas_objectif_id' => (int) $objectif->id,
                'direction_id' => (int) $direction->id,
            ]),
            'fingerprint' => 'missing_pao_coverage:'.$objectif->id.':'.$direction->id,
        ];
    }

    private function scopeAction(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    private function scopeJoinedPta(
        Builder $query,
        User $user,
        string $directionColumn,
        string $serviceColumn
    ): void {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        $directionIds = $user->delegatedDirectionIds('planning_read');
        $directionIds = array_merge($directionIds, $user->delegatedDirectionIds('planning_write'));
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $directionIds[] = (int) $user->direction_id;
        }
        $directionIds = array_values(array_unique(array_filter($directionIds, static fn ($id): bool => (int) $id > 0)));

        $serviceScopes = array_merge(
            $user->delegatedServiceScopes('planning_read'),
            $user->delegatedServiceScopes('planning_write')
        );
        if ($user->hasRole(User::ROLE_SERVICE) && $user->direction_id !== null && $user->service_id !== null) {
            $serviceScopes[] = [
                'direction_id' => (int) $user->direction_id,
                'service_id' => (int) $user->service_id,
            ];
        }

        if ($directionIds === [] && $serviceScopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $scopedQuery) use ($directionIds, $serviceScopes, $directionColumn, $serviceColumn): void {
            foreach ($directionIds as $directionId) {
                $scopedQuery->orWhere($directionColumn, (int) $directionId);
            }

            foreach ($serviceScopes as $scope) {
                $scopedQuery->orWhere(function (Builder $subQuery) use ($directionColumn, $serviceColumn, $scope): void {
                    $subQuery
                        ->where($directionColumn, (int) $scope['direction_id'])
                        ->where($serviceColumn, (int) $scope['service_id']);
                });
            }
        });
    }

    private function directionLabel(mixed $direction): string
    {
        $code = is_object($direction) ? (string) ($direction->code ?? '') : '';
        $label = is_object($direction) ? (string) ($direction->libelle ?? '') : '';

        return trim($code !== '' ? $code.' - '.$label : $label) ?: 'Non renseignee';
    }

    private function serviceLabel(mixed $service): string
    {
        if (! is_object($service)) {
            return 'Non renseigne';
        }

        $code = (string) ($service->code ?? '');
        $label = (string) ($service->libelle ?? '');

        return trim($code !== '' ? $code.' - '.$label : $label) ?: 'Non renseigne';
    }

    private function levelLabel(string $level): string
    {
        return match ($level) {
            'critical' => 'Critique',
            'warning' => 'Attention',
            'info' => 'Info',
            default => ucfirst($level),
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'retard' => 'Retard',
            'action_non_demarre' => 'Non demarree',
            'pao_manquant' => 'PAO manquant',
            'kpi_global' => 'KPI global',
            'kpi_sous_seuil' => 'KPI sous seuil',
            'periode_manquante' => 'Periode manquante',
            'ecart_progression' => 'Ecart progression',
            'validation_bloquee' => 'Validation bloquee',
            'delegation_expiration' => 'Delegation',
            'action_soumise_validation' => 'Soumission',
            'action_validee_chef' => 'Validation chef',
            'action_rejetee_chef' => 'Rejet chef',
            'action_validee_direction' => 'Validation direction',
            'action_rejetee_direction' => 'Rejet direction',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function logTitle(ActionLog $log): string
    {
        return match ((string) $log->type_evenement) {
            'periode_manquante' => 'Periode non renseignee',
            'ecart_progression' => 'Ecart de progression',
            'validation_bloquee' => 'Validation bloquee',
            'action_soumise_validation' => 'Action soumise pour validation',
            'action_validee_chef' => 'Action validee par le chef',
            'action_rejetee_chef' => 'Action rejetee par le chef',
            'action_validee_direction' => 'Action validee par la direction',
            'action_rejetee_direction' => 'Action rejetee par la direction',
            default => $this->typeLabel((string) $log->type_evenement),
        };
    }

    /**
     * @return array<int, int>
     */
    private function scopedDirectionIds(User $user): array
    {
        if ($user->hasGlobalReadAccess()) {
            return Direction::query()
                ->where('actif', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        if ($user->direction_id !== null) {
            return [(int) $user->direction_id];
        }

        return [];
    }

    private function encodeCoverageIdentifier(int $objectifId, int $directionId): int
    {
        return ($objectifId * 100000) + $directionId;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function decodeCoverageIdentifier(int $value): array
    {
        return [
            (int) floor($value / 100000),
            $value % 100000,
        ];
    }
}
