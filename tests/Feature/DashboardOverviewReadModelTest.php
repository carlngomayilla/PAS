<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Dashboard\DashboardOverviewReadModel;
use App\Services\RolePermissionSettings;
use App\Services\RoleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class DashboardOverviewReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_applies_cross_organization_filters_and_exposes_only_primitives(): void
    {
        [$firstDirection, $firstService] = $this->createOrganization('FIRST', 'Première direction');
        [$selectedDirection, $selectedService] = $this->createOrganization('SELECTED', 'Direction sélectionnée');
        $this->createAction($firstDirection, $firstService, 'FIRST', 'Action hors filtre');
        $selectedAction = $this->createAction($selectedDirection, $selectedService, 'SELECTED', 'Action sélectionnée');
        $user = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'direction_id' => $firstDirection->id,
            'service_id' => $firstService->id,
        ]);
        $readModel = $this->readModel($user, [
            'direction_id' => $selectedDirection->id,
            'service_id' => $selectedService->id,
        ]);

        $snapshot = $readModel->read($user, false);
        $payload = $snapshot->toPayload();

        $this->assertSame([$selectedAction->id], $snapshot->dashboardActions->pluck('id')->all());
        $this->assertSame(1, data_get($snapshot->metrics(), 'totals.actions_total'));
        $this->assertSame($selectedDirection->id, data_get($payload, 'scope.direction_id'));
        $this->assertSame($selectedService->id, data_get($payload, 'scope.service_id'));
        $this->assertSame($selectedDirection->id, data_get($payload, 'scope.selected_direction_id'));
        $this->assertSame($selectedService->id, data_get($payload, 'scope.selected_service_id'));
        $this->assertSame($selectedDirection->id, data_get($payload, 'direction_selector.selected_id'));
        $this->assertSame([
            'scope',
            'direction_selector',
            'filters',
            'filter_options',
            'exercise',
            'metrics',
            'links',
            'synthesis_decision_summary',
            'financial_summary',
            'generated_at',
        ], array_keys($payload));
        $this->assertSame([
            'years',
            'quarters',
            'periods',
            'action_statuses',
            'tracking_statuses',
            'delay_statuses',
            'deadline_alerts',
            'responsibles',
        ], array_keys($payload['filter_options']));
        $this->assertContains(
            (int) $selectedAction->responsable_id,
            collect($payload['filter_options']['responsibles'])->pluck('id')->all(),
        );
        $this->assertSame([
            'blade_pilotage',
            'tables',
            'charts',
            'actions',
            'pas',
            'paos',
            'ptas',
            'late_actions',
            'kpi_below_threshold',
            'reporting',
            'alerts',
            'pta_tracking',
            'breakdowns',
        ], array_keys($payload['links']));
        $this->assertSafeRelativeLinks($payload['links']);
        $this->assertSame([
            'actions',
            'workflow',
            'alerts',
        ], array_keys($payload['links']['breakdowns']));
        $this->assertSame(
            'acheve',
            $this->queryParameters($payload['links']['breakdowns']['actions']['acheve'])['statut_action'] ?? null,
        );
        $this->assertSame(
            (string) $selectedDirection->id,
            $this->queryParameters($payload['links']['breakdowns']['actions']['acheve'])['direction_id'] ?? null,
        );
        $this->assertArrayHasKey('a_corriger', $payload['links']['breakdowns']['actions']);
        $this->assertSame(
            'advanced',
            $this->queryParameters($payload['links']['breakdowns']['workflow']['validation_chef'])['dashboardTab'] ?? null,
        );
        $this->assertSame(
            'validation_chef',
            $this->queryParameters($payload['links']['breakdowns']['workflow']['validation_chef'])['statut_suivi'] ?? null,
        );
        $this->assertSame(
            'critique',
            $this->queryParameters($payload['links']['breakdowns']['alerts']['critique'])['alerte_echeance'] ?? null,
        );
        $this->assertSame($payload['generated_at'], $snapshot->toPayload()['generated_at']);
        $this->assertNull($payload['financial_summary']);
        $this->assertPayloadContainsOnlyPrimitives($payload);
        $this->assertJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_effective_global_profiles_keep_the_same_pilotage_scope_as_direct_profiles(): void
    {
        [$homeDirection, $homeService] = $this->createOrganization('PROFILE-HOME', 'Direction de rattachement');
        [$dataDirection, $dataService] = $this->createOrganization('PROFILE-DATA', 'Direction des actions');
        $pilotageAction = $this->createAction(
            $dataDirection,
            $dataService,
            'PROFILE-PILOTAGE',
            'Action de pilotage globale',
        );
        $operationalAction = $pilotageAction->replicate();
        $operationalAction->forceFill([
            'libelle' => 'Action operationnelle hors pilotage',
            'contexte_action' => Action::CONTEXT_OPERATIONNEL,
        ])->save();

        app(RoleRegistryService::class)->updateCustomRoles([
            [
                'code' => 'planification_personnalisee',
                'label' => 'Planification personnalisee',
                'base_role' => User::ROLE_PLANIFICATION,
                'description' => 'Profil de planification derive pour le test dashboard.',
                'active' => true,
            ],
        ]);

        $profiles = [
            'planification' => [User::ROLE_PLANIFICATION, User::ROLE_PLANIFICATION],
            'SCIQ' => [User::ROLE_SCIQ, User::ROLE_SCIQ],
            'chef planification' => [User::ROLE_CHEF_PLANIFICATION, User::ROLE_CHEF_PLANIFICATION],
            'chef unite SCIQ' => [User::ROLE_CHEF_UNITE_SCIQ, User::ROLE_CHEF_UNITE_SCIQ],
            'DG' => [User::ROLE_DG, User::ROLE_DG],
            'role personnalise derive de planification' => [User::ROLE_PLANIFICATION, 'planification_personnalisee'],
        ];

        foreach ($profiles as $label => [$directRole, $effectiveRole]) {
            $directUser = User::factory()->create([
                'role' => $directRole,
                'custom_role_code' => null,
                'direction_id' => $homeDirection->id,
                'service_id' => $homeService->id,
            ]);
            $effectiveUser = User::factory()->create([
                'role' => User::ROLE_AGENT,
                'custom_role_code' => $effectiveRole,
                'direction_id' => $homeDirection->id,
                'service_id' => $homeService->id,
            ]);

            $directSnapshot = $this->readModel($directUser, [])->read($directUser, false);
            $effectiveSnapshot = $this->readModel($effectiveUser, [])->read($effectiveUser, false);
            $directPayload = $directSnapshot->toPayload();
            $effectivePayload = $effectiveSnapshot->toPayload();

            $this->assertTrue($effectiveUser->isAgent(), $label);
            $this->assertTrue($effectiveUser->hasCrossOrganizationDashboardAccess(), $label);
            $this->assertSame('pilotage', data_get($directPayload, 'scope.mode'), $label);
            $this->assertSame('pilotage', data_get($effectivePayload, 'scope.mode'), $label);
            $this->assertTrue(data_get($effectivePayload, 'scope.cross_organization_filters'), $label);
            $this->assertEqualsCanonicalizing(
                [$pilotageAction->id, $operationalAction->id],
                $effectiveSnapshot->visibleActions->pluck('id')->all(),
                $label,
            );
            $this->assertSame([$pilotageAction->id], $directSnapshot->dashboardActions->pluck('id')->all(), $label);
            $this->assertSame([$pilotageAction->id], $effectiveSnapshot->dashboardActions->pluck('id')->all(), $label);
            $this->assertSame(
                data_get($directPayload, 'metrics.totals'),
                data_get($effectivePayload, 'metrics.totals'),
                $label,
            );
            $this->assertSame(1, data_get($effectivePayload, 'metrics.totals.pas_total'), $label);
            $this->assertSame(1, data_get($effectivePayload, 'metrics.totals.actions_total'), $label);
        }
    }

    public function test_responsable_filter_includes_an_agent_assigned_through_a_sub_action(): void
    {
        [$direction, $service] = $this->createOrganization('RMO', 'Direction RMO');
        $action = $this->createAction($direction, $service, 'RMO', 'Action suivie par sous-action');
        $rmo = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $rmo->id,
            'libelle' => 'Sous-action RMO',
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'est_effectuee' => false,
            'taux_execution' => 0,
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
        ]);
        $planning = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $readModel = $this->readModel($planning, ['responsable_id' => $rmo->id]);

        $snapshot = $readModel->read($planning, false);

        $this->assertSame([$action->id], $snapshot->scopedActions->pluck('id')->all());
        $this->assertSame([$action->id], $snapshot->dashboardActions->pluck('id')->all());
        $this->assertSame(1, data_get($snapshot->metrics(), 'totals.actions_total'));
        $this->assertSame($rmo->id, data_get($snapshot->toPayload(), 'filters.responsable_id'));
        $this->assertSame(
            (string) $rmo->id,
            $this->queryParameters(
                data_get($snapshot->toPayload(), 'links.breakdowns.actions.en_cours')
            )['responsable_id'] ?? null,
        );
    }

    public function test_action_status_filter_uses_the_same_calculated_status_as_the_breakdown(): void
    {
        [$direction, $service] = $this->createOrganization('STATUS', 'Direction statuts');
        $completed = $this->createAction($direction, $service, 'STATUS-DONE', 'Action clôturée');
        $ongoingResponsible = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $ongoing = $completed->replicate();
        $ongoing->forceFill([
            'libelle' => 'Action en cours',
            'responsable_id' => $ongoingResponsible->id,
        ])->save();
        $completed->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'progression_reelle' => 40,
            'cloture_le' => now(),
        ])->save();
        $planning = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $readModel = $this->readModel($planning, [
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'statut_action' => 'acheve',
        ]);

        $snapshot = $readModel->read($planning, false);
        $payload = $snapshot->toPayload();

        $this->assertSame([$completed->id], $snapshot->dashboardActions->pluck('id')->all());
        $this->assertNotContains($ongoing->id, $snapshot->dashboardActions->pluck('id')->all());
        $this->assertSame('acheve', data_get($payload, 'filters.statut_action'));
        $this->assertSame(1, data_get($payload, 'metrics.status_breakdown.actions.acheve'));
    }

    public function test_responsible_period_and_status_filters_bound_every_action_dependent_metric_and_link(): void
    {
        [$direction, $service] = $this->createOrganization('BOUND', 'Direction pÃ©rimÃ¨tre filtrÃ©');
        $selectedResponsible = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $otherResponsible = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $selectedAction = $this->createAction(
            $direction,
            $service,
            'BOUND-Q1',
            'Action filtrÃ©e du premier trimestre',
            $selectedResponsible,
        );
        $otherAction = $selectedAction->replicate();
        $otherAction->forceFill([
            'libelle' => 'Action hors filtres du deuxiÃ¨me trimestre',
            'responsable_id' => $otherResponsible->id,
        ])->save();
        $year = now()->year;
        $selectedAction->forceFill([
            'date_debut' => $year.'-01-05',
            'date_fin' => $year.'-03-15',
            'date_echeance' => $year.'-03-15',
            'cloture_le' => $year.'-03-10 10:00:00',
        ])->save();
        $otherAction->forceFill([
            'date_debut' => $year.'-04-05',
            'date_fin' => $year.'-06-15',
            'date_echeance' => $year.'-06-15',
            'cloture_le' => null,
        ])->save();

        $selectedKpi = Kpi::query()->create([
            'action_id' => $selectedAction->id,
            'libelle' => 'KPI filtrÃ©',
            'unite' => '%',
            'cible' => 100,
            'seuil_alerte' => 50,
        ]);
        $otherKpi = Kpi::query()->create([
            'action_id' => $otherAction->id,
            'libelle' => 'KPI hors filtres',
            'unite' => '%',
            'cible' => 100,
            'seuil_alerte' => 50,
        ]);
        KpiMesure::query()->create([
            'kpi_id' => $selectedKpi->id,
            'periode' => $year.'-Q1',
            'valeur' => 25,
        ]);
        KpiMesure::query()->create([
            'kpi_id' => $otherKpi->id,
            'periode' => $year.'-Q2',
            'valeur' => 20,
        ]);
        foreach ([$selectedAction, $otherAction] as $action) {
            ActionLog::query()->create([
                'action_id' => $action->id,
                'niveau' => 'warning',
                'type_evenement' => 'dashboard_scope_test',
                'message' => 'Alerte active de test',
                'details' => ['resolved' => false],
            ]);
        }

        $planning = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $readModel = $this->readModel($planning, [
            'exercice' => $year,
            'periode' => 'q1',
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'responsable_id' => $selectedResponsible->id,
            'statut_action' => 'acheve',
        ]);

        $snapshot = $readModel->read($planning, false);
        $payload = $snapshot->toPayload();

        $this->assertSame([$selectedAction->id], $snapshot->dashboardActions->pluck('id')->all());
        $this->assertSame(1, data_get($payload, 'metrics.totals.actions_total'));
        $this->assertSame(1, data_get($payload, 'metrics.totals.pas_total'));
        $this->assertSame(1, data_get($payload, 'metrics.totals.paos_total'));
        $this->assertSame(1, data_get($payload, 'metrics.totals.ptas_total'));
        $this->assertSame(1, data_get($payload, 'metrics.totals.kpis_total'));
        $this->assertSame(1, data_get($payload, 'metrics.totals.kpi_mesures_total'));
        $this->assertSame(1, data_get($payload, 'metrics.alerts.mesures_kpi_sous_seuil'));
        $this->assertSame(1, data_get($payload, 'metrics.alerts.alertes_action_actives'));
        $this->assertSame(1, data_get($payload, 'metrics.status_breakdown.actions.acheve'));
        $this->assertSame(0, data_get($payload, 'metrics.status_breakdown.actions.a_corriger'));

        foreach (['actions', 'pas', 'paos', 'ptas', 'kpi_below_threshold', 'reporting', 'alerts', 'pta_tracking'] as $linkKey) {
            $parameters = $this->queryParameters((string) data_get($payload, 'links.'.$linkKey));
            $this->assertSame('advanced', $parameters['dashboardTab'] ?? null);
            $this->assertSame('q1', $parameters['periode'] ?? null);
            $this->assertSame((string) $direction->id, $parameters['direction_id'] ?? null);
            $this->assertSame((string) $service->id, $parameters['service_id'] ?? null);
            $this->assertSame((string) $selectedResponsible->id, $parameters['responsable_id'] ?? null);
            $this->assertSame('acheve', $parameters['statut_action'] ?? null);
        }

        $lateActionParameters = $this->queryParameters((string) data_get($payload, 'links.late_actions'));
        $this->assertSame('advanced', $lateActionParameters['dashboardTab'] ?? null);
        $this->assertSame('q1', $lateActionParameters['periode'] ?? null);
        $this->assertSame((string) $selectedResponsible->id, $lateActionParameters['responsable_id'] ?? null);
        $this->assertSame('en_retard', $lateActionParameters['statut_action'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $links
     */
    private function assertSafeRelativeLinks(array $links): void
    {
        foreach ($links as $link) {
            if (is_array($link)) {
                $this->assertSafeRelativeLinks($link);

                continue;
            }

            $this->assertIsString($link);
            $this->assertStringStartsWith('/', $link);
            $this->assertFalse(str_starts_with($link, '//'));
            $this->assertStringNotContainsString(chr(92), $link);
        }
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(string $link): array
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $parameters);

        return collect($parameters)
            ->filter(static fn (mixed $value): bool => is_scalar($value))
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }

    public function test_agent_scope_unites_personal_assignments_and_active_delegations_without_leaking(): void
    {
        [$homeDirection, $homeService] = $this->createOrganization('HOME', 'Direction propre');
        [$delegatedDirection, $delegatedService] = $this->createOrganization('DELEGATED', 'Direction déléguée');
        [$foreignDirection, $foreignService] = $this->createOrganization('FOREIGN', 'Direction hors périmètre');
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $homeDirection->id,
            'service_id' => $homeService->id,
        ]);
        $this->createAction($homeDirection, $homeService, 'HOME', 'Action personnelle', $agent);
        $delegatedAction = $this->createAction($delegatedDirection, $delegatedService, 'DELEGATED', 'Action déléguée');
        $foreignAction = $this->createAction($foreignDirection, $foreignService, 'FOREIGN', 'Action confidentielle');
        $delegant = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $delegatedDirection->id,
        ]);
        $delegation = Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $agent->id,
            'role_scope' => Delegation::SCOPE_DIRECTION,
            'direction_id' => $delegatedDirection->id,
            'service_id' => null,
            'permissions' => ['planning_read'],
            'motif' => 'Continuité de pilotage',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addWeek(),
            'statut' => 'active',
            'cree_par' => $delegant->id,
        ]);
        $readModel = $this->readModel($agent, [
            'direction_id' => $delegatedDirection->id,
            'service_id' => $delegatedService->id,
        ]);
        $activeDimensions = $readModel->cacheDimensions($agent);

        $snapshot = $readModel->read($agent, false);

        $this->assertSame([$delegatedAction->id], $snapshot->dashboardActions->pluck('id')->all());
        $this->assertNotContains($foreignAction->id, $snapshot->allScopedActions->pluck('id')->all());
        $this->assertTrue(data_get($snapshot->toPayload(), 'scope.organization_filters_enabled'));
        $this->assertFalse(data_get($snapshot->toPayload(), 'scope.cross_organization_filters'));
        $this->assertSame(
            collect([$homeDirection->id, $delegatedDirection->id])->sort()->values()->all(),
            collect(data_get($snapshot->toPayload(), 'direction_selector.options', []))
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
        );

        $delegation->update(['statut' => 'cancelled']);
        $cancelledDimensions = app(DashboardOverviewReadModel::class)
            ->cacheDimensions($agent->fresh());

        $this->assertNotSame($activeDimensions['active_delegations'], $cancelledDimensions['active_delegations']);
    }

    public function test_cache_dimensions_hash_sorted_resolved_permissions_and_change_with_rbac(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_AGENT]);
        $permissionSettings = Mockery::mock(RolePermissionSettings::class);
        $permissionSettings->shouldReceive('forUser')
            ->twice()
            ->with($user)
            ->andReturn(
                ['reporting.read', 'planning.read', 'planning.read', ' '],
                ['planning.read'],
            );
        $this->app->instance(RolePermissionSettings::class, $permissionSettings);
        $readModel = app(DashboardOverviewReadModel::class);

        $firstDimensions = $readModel->cacheDimensions($user);
        $secondDimensions = $readModel->cacheDimensions($user);

        $this->assertSame(
            sha1(json_encode(['planning.read', 'reporting.read'], JSON_THROW_ON_ERROR)),
            $firstDimensions['resolved_permissions'],
        );
        $this->assertSame(
            sha1(json_encode(['planning.read'], JSON_THROW_ON_ERROR)),
            $secondDimensions['resolved_permissions'],
        );
        $this->assertNotSame(
            $firstDimensions['resolved_permissions'],
            $secondDimensions['resolved_permissions'],
        );
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function readModel(User $user, array $query): DashboardOverviewReadModel
    {
        $request = Request::create('/dashboard', 'GET', $query);
        $request->setUserResolver(static fn (): User => $user);
        $this->app->instance('request', $request);

        return app(DashboardOverviewReadModel::class)->useRequest($request);
    }

    /**
     * @return array{Direction, Service}
     */
    private function createOrganization(string $code, string $label): array
    {
        $direction = Direction::query()->create([
            'code' => $code,
            'libelle' => $label,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => $code.'-SERVICE',
            'libelle' => 'Service '.$label,
            'actif' => true,
        ]);

        return [$direction, $service];
    }

    private function createAction(
        Direction $direction,
        Service $service,
        string $suffix,
        string $label,
        ?User $responsable = null,
    ): Action {
        $responsable ??= User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS '.$suffix,
            'periode_debut' => now()->year,
            'periode_fin' => now()->year + 2,
            'statut' => 'actif',
        ]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-'.$suffix,
            'libelle' => 'Axe '.$suffix,
            'ordre' => 1,
        ]);
        $strategicObjective = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-'.$suffix,
            'libelle' => 'Objectif stratégique '.$suffix,
            'date_echeance' => now()->addYears(2)->toDateString(),
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => now()->year,
            'titre' => 'PAO '.$suffix,
            'objectif_operationnel' => 'Objectif opérationnel '.$suffix,
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $operationalObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif opérationnel '.$suffix,
            'echeance' => now()->addYear()->toDateString(),
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA '.$suffix,
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        return Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'libelle' => $label,
            'description' => 'Action de vérification du read model',
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'type_cible' => 'quantitative',
            'type_indicateur' => 'quantitatif',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'quantite_a_realiser' => 10,
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
            'date_echeance' => now()->addMonth()->toDateString(),
            'echeance_cible' => now()->addMonth()->toDateString(),
            'responsable_id' => $responsable->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'statut_parametrage' => 'parametre',
            'progression_reelle' => 40,
            'progression_theorique' => 50,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);
    }

    private function assertPayloadContainsOnlyPrimitives(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $this->assertPayloadContainsOnlyPrimitives($nestedValue);
            }

            return;
        }

        $this->assertTrue(
            $value === null || is_scalar($value),
            'Le payload API ne doit contenir ni modèle Eloquent ni Collection.',
        );
    }
}
