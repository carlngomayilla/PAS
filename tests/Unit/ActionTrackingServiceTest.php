<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Models\ActionWeek;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Alerting\AlertCenterService;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ActionTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_metrics_computes_completed_action_kpis_and_status(): void
    {
        $action = $this->createQuantitativeAction();

        ActionWeek::query()->create([
            'action_id' => $action->id,
            'numero_semaine' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-07',
            'est_renseignee' => true,
            'quantite_realisee' => 100,
            'saisi_par' => $action->responsable_id,
            'saisi_le' => now(),
        ]);

        $action->update([
            'date_fin_reelle' => '2026-01-07',
            'validation_sans_correction' => true,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ]);

        $service = app(ActionTrackingService::class);
        $service->refreshActionMetrics($action, Carbon::parse('2026-01-07'));

        $action->refresh()->load('actionKpi');

        $this->assertSame(ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, $action->statut_dynamique);
        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertNotNull($action->actionKpi);
        $this->assertSame('100.00', (string) $action->actionKpi->kpi_performance);
        $this->assertSame('100.00', (string) $action->actionKpi->kpi_delai);
    }

    public function test_refresh_metrics_marks_action_late_when_deadline_passes_without_completion(): void
    {
        $action = $this->createQuantitativeAction();

        ActionWeek::query()->create([
            'action_id' => $action->id,
            'numero_semaine' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-07',
            'est_renseignee' => true,
            'quantite_realisee' => 10,
            'saisi_par' => $action->responsable_id,
            'saisi_le' => now(),
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        $action->refresh()->load('actionKpi');

        $this->assertSame(ActionTrackingService::STATUS_EN_RETARD, $action->statut_dynamique);
        $this->assertSame('10.00', (string) $action->progression_reelle);
        $this->assertNotNull($action->actionKpi);
        $this->assertLessThan(100, (float) $action->actionKpi->kpi_global);
    }

    public function test_refresh_metrics_marks_non_started_action_as_late_after_deadline(): void
    {
        $action = $this->createQuantitativeAction();

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        $action->refresh();

        $this->assertSame(ActionTrackingService::STATUS_EN_RETARD, $action->statut_dynamique);
        $this->assertSame('0.00', (string) $action->progression_reelle);
    }

    public function test_refresh_metrics_does_not_create_echeance_proche_log_after_deadline(): void
    {
        $action = $this->createQuantitativeAction();

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        $this->assertDatabaseMissing('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'echeance_proche',
        ]);
    }

    public function test_refresh_metrics_marks_action_at_risk_near_deadline(): void
    {
        $action = $this->createQuantitativeAction([
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-12',
            'date_echeance' => '2026-01-12',
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-10'));

        $action->refresh();

        $this->assertSame(ActionTrackingService::STATUS_A_RISQUE, $action->statut_dynamique);
    }

    public function test_refresh_metrics_marks_action_completed_from_real_end_even_before_direction_validation(): void
    {
        $action = $this->createQuantitativeAction([
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
        ]);

        $action->update([
            'date_fin_reelle' => '2026-01-09',
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-09'));

        $action->refresh();

        $this->assertSame(ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, $action->statut_dynamique);
    }

    public function test_refresh_metrics_creates_combined_urgency_alert_for_overdue_low_kpi(): void
    {
        $action = $this->createQuantitativeAction([
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'alerte_combinee_critique',
            'niveau' => 'urgence',
            'cible_role' => 'dg',
        ]);
    }

    public function test_refresh_metrics_notifies_escalation_chain_for_combined_critical_alert(): void
    {
        Notification::fake();

        $action = $this->createQuantitativeAction([
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
        ]);

        $action->loadMissing('pta:id,direction_id,service_id');
        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'email' => 'service.alert@test.local',
            'password_changed_at' => now(),
        ]);
        $directionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $directionId,
            'service_id' => null,
            'email' => 'direction.alert@test.local',
            'password_changed_at' => now(),
        ]);
        $planningUser = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'direction_id' => null,
            'service_id' => null,
            'email' => 'planning.alert@test.local',
            'password_changed_at' => now(),
        ]);
        $dgUser = User::factory()->create([
            'role' => User::ROLE_DG,
            'direction_id' => null,
            'service_id' => null,
            'email' => 'dg.alert@test.local',
            'password_changed_at' => now(),
        ]);
        $outsider = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'email' => 'outsider.alert@test.local',
            'password_changed_at' => now(),
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        foreach ([$serviceUser, $directionUser, $planningUser, $dgUser] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                WorkspaceModuleNotification::class,
                function (WorkspaceModuleNotification $notification, array $channels) use ($recipient): bool {
                    $data = $notification->toArray($recipient);

                    return $channels === ['database']
                        && (string) ($data['module'] ?? '') === 'alertes'
                        && (string) ($data['meta']['event'] ?? '') === 'action_alert'
                        && (string) ($data['meta']['cible_role'] ?? '') === 'dg';
                }
            );
        }

        Notification::assertNotSentTo($outsider, WorkspaceModuleNotification::class);
    }

    public function test_refresh_metrics_freezes_kpis_and_skips_automatic_alerts_for_suspended_action(): void
    {
        $action = $this->createQuantitativeAction();

        ActionWeek::query()->create([
            'action_id' => $action->id,
            'numero_semaine' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-07',
            'est_renseignee' => true,
            'quantite_realisee' => 30,
            'saisi_par' => $action->responsable_id,
            'saisi_le' => now(),
        ]);

        $service = app(ActionTrackingService::class);
        $service->refreshActionMetrics($action, Carbon::parse('2026-01-08'));

        $action->refresh()->load('actionKpi');
        $initialGlobal = (float) ($action->actionKpi?->kpi_global ?? 0.0);

        $action->update([
            'statut' => ActionTrackingService::STATUS_SUSPENDU,
        ]);

        $service->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        $action->refresh()->load('actionKpi');

        $this->assertSame(ActionTrackingService::STATUS_SUSPENDU, $action->statut_dynamique);
        $this->assertSame($initialGlobal, (float) ($action->actionKpi?->kpi_global ?? 0.0));
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'action_suspendue',
        ]);
        $this->assertDatabaseMissing('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'alerte_combinee_critique',
        ]);
    }

    public function test_refresh_metrics_annuls_action_and_zeroes_kpis_without_automatic_alerts(): void
    {
        $action = $this->createQuantitativeAction();

        $action->update([
            'statut' => ActionTrackingService::STATUS_ANNULE,
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        $action->refresh()->load('actionKpi');

        $this->assertSame(ActionTrackingService::STATUS_ANNULE, $action->statut_dynamique);
        $this->assertSame('0.00', (string) ($action->actionKpi?->kpi_global ?? 0));
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'action_annulee',
        ]);
        $this->assertDatabaseMissing('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'kpi_global_sous_seuil',
        ]);
    }

    public function test_refresh_metrics_penalizes_conformity_and_logs_warning_when_due_weeks_are_missing(): void
    {
        $action = $this->createQuantitativeAction();

        ActionWeek::query()->create([
            'action_id' => $action->id,
            'numero_semaine' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-07',
            'est_renseignee' => false,
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-10'));

        $action->refresh()->load('actionKpi');

        $this->assertLessThan(85.0, (float) ($action->actionKpi?->kpi_conformite ?? 100.0));
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'conformite_incomplete',
            'niveau' => 'warning',
        ]);
    }

    public function test_refresh_metrics_logs_missing_justificatif_warning_when_execution_has_no_supporting_documents(): void
    {
        $action = $this->createQuantitativeAction([
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
        ]);

        $action->update([
            'date_fin_reelle' => '2026-01-09',
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-09'));

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'justificatif_absent',
            'niveau' => 'warning',
        ]);
    }

    public function test_execution_justificatif_resolves_missing_justificatif_alert(): void
    {
        $action = $this->createQuantitativeAction([
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
        ]);
        $action->update([
            'date_fin_reelle' => '2026-01-09',
        ]);

        $trackingService = app(ActionTrackingService::class);
        $trackingService->refreshActionMetrics($action, Carbon::parse('2026-01-09'));

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'justificatif_absent',
            'niveau' => 'warning',
        ]);

        $trackingService->addActionJustificatif(
            $action,
            null,
            'execution_quantitative',
            'justificatifs/test.pdf',
            'test.pdf',
            'application/pdf',
            128,
            'Justificatif test',
            null,
            false
        );

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'justificatif_absent',
            'niveau' => 'info',
            'lu' => true,
        ]);

        $agent = $action->responsable()->firstOrFail();
        $alerts = app(AlertCenterService::class)->buildForUser($agent, 20);

        $this->assertFalse($alerts->contains(
            fn (array $alert): bool => ($alert['type'] ?? null) === 'justificatif_absent'
                && (int) ($alert['action']['id'] ?? 0) === (int) $action->id
        ));
    }

    public function test_non_started_action_keeps_zero_progress_and_execution_performance(): void
    {
        $action = $this->createQuantitativeAction([
            'quantite_cible' => 500,
            'quantite_realisee' => 0,
            'date_fin' => '2026-06-30',
            'date_echeance' => '2026-06-30',
            'evaluation_note' => 80,
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-10'));

        $action->refresh()->load('actionKpi');

        $this->assertSame('0.00', (string) $action->progression_reelle);
        $this->assertSame('0.00', (string) ($action->actionKpi?->kpi_performance ?? 0));
        $this->assertSame('0.00', (string) ($action->actionKpi?->kpi_global ?? 0));
    }

    public function test_started_quantitative_action_uses_real_progress_delay_and_quality_without_risk(): void
    {
        $action = $this->createQuantitativeAction([
            'quantite_cible' => 500,
            'quantite_realisee' => 300,
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
            'evaluation_note' => 80,
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-10'));

        $action->refresh()->load('actionKpi');

        $this->assertSame('60.00', (string) $action->progression_reelle);
        $this->assertSame('100.00', (string) ($action->actionKpi?->kpi_delai ?? 0));
        $this->assertSame('80.00', (string) ($action->actionKpi?->kpi_qualite ?? 0));
        $this->assertSame('70.00', (string) ($action->actionKpi?->kpi_performance ?? 0));
    }

    public function test_sub_action_progress_uses_completed_sub_actions_ratio(): void
    {
        $action = $this->createQuantitativeAction([
            'type_cible' => 'qualitative',
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'quantite_cible' => 0,
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
        ]);

        foreach (range(1, 4) as $index) {
            SousAction::query()->create([
                'action_id' => $action->id,
                'agent_id' => $action->responsable_id,
                'libelle' => 'Sous-action '.$index,
                'date_debut' => '2026-01-01',
                'date_fin' => '2026-01-31',
                'statut' => $index === 1 ? 'terminee' : 'a_faire',
                'est_effectuee' => $index === 1,
                'taux_execution' => $index === 1 ? 100 : 0,
            ]);
        }

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-10'));

        $action->refresh();

        $this->assertSame('25.00', (string) $action->progression_reelle);
    }

    public function test_completed_action_with_full_target_delay_and_quality_reaches_full_execution_performance(): void
    {
        $action = $this->createQuantitativeAction([
            'quantite_cible' => 100,
            'quantite_realisee' => 100,
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
            'date_fin_reelle' => '2026-01-20',
            'evaluation_note' => 100,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-20'));

        $action->refresh()->load('actionKpi');

        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertSame('100.00', (string) ($action->actionKpi?->kpi_performance ?? 0));
    }

    public function test_action_without_target_or_sub_actions_stays_at_zero_progress_and_performance(): void
    {
        $action = $this->createQuantitativeAction([
            'type_cible' => 'qualitative',
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'quantite_cible' => 0,
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
            'evaluation_note' => 100,
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-01-10'));

        $action->refresh()->load('actionKpi');

        $this->assertSame('0.00', (string) $action->progression_reelle);
        $this->assertSame('0.00', (string) ($action->actionKpi?->kpi_performance ?? 0));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createQuantitativeAction(array $overrides = []): Action
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-U',
            'libelle' => 'Direction Unit Test',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-U',
            'libelle' => 'Service Unit Test',
            'actif' => true,
        ]);

        $responsable = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'UT-AG-01',
            'agent_fonction' => 'Agent test',
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS UT',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-UT',
            'libelle' => 'Axe UT',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-UT-1',
            'libelle' => 'Objectif UT',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO UT',
            'statut' => 'brouillon',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA UT',
            'statut' => 'brouillon',
        ]);

        return Action::query()->create(array_merge([
            'pta_id' => $pta->id,
            'libelle' => 'Action UT',
            'description' => 'Action unitaire',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $responsable->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ], $overrides));
    }
}
