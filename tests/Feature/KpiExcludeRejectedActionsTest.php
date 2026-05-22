<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\ActionCalculationSettings;
use App\Services\Actions\ActionTrackingService;
use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiExcludeRejectedActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_policy_requires_direction_validation(): void
    {
        /** @var ActionCalculationSettings $settings */
        $settings = app(ActionCalculationSettings::class);

        $this->assertSame(ActionCalculationSettings::LEVEL_VALIDATION_DIRECTION, $settings->statisticalScope());
        $this->assertSame([ActionTrackingService::VALIDATION_VALIDEE_DIRECTION], $settings->statisticalValidationStatuses());
        $this->assertSame([], $settings->rejectedValidationStatuses());
    }

    public function test_rejected_actions_are_excluded_from_consolidated_kpis(): void
    {
        [$admin, $pta] = $this->createPlanningFixture();

        Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action validee direction',
            'description' => 'Validee',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'Livrer',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $admin->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'progression_reelle' => 80,
            'progression_theorique' => 80,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ]);

        Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action rejetee chef',
            'description' => 'A ignorer',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'Livrer',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $admin->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'progression_reelle' => 10,
            'progression_theorique' => 50,
            'statut_validation' => ActionTrackingService::VALIDATION_REJETEE_CHEF,
        ]);

        Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action rejetee direction',
            'description' => 'A ignorer',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'Livrer',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $admin->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'progression_reelle' => 30,
            'progression_theorique' => 60,
            'statut_validation' => ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        ]);

        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, false, false);

        // 1 seule action (validee_direction) doit compter ; les 2 rejetees sont exclues.
        $this->assertSame(1, $payload['global']['actions_validees']);
        $this->assertSame(80.0, (float) $payload['pasConsolidation'][0]['progression_moyenne']);
    }

    public function test_super_admin_can_switch_back_to_all_visible_scope(): void
    {
        /** @var ActionCalculationSettings $settings */
        $settings = app(ActionCalculationSettings::class);
        $settings->updateStatisticalPolicy([
            ActionCalculationSettings::SETTING_ACTIONS_STATISTICAL_SCOPE => ActionCalculationSettings::STATISTICAL_SCOPE_ALL_VISIBLE,
        ]);

        $this->assertSame(ActionCalculationSettings::STATISTICAL_SCOPE_ALL_VISIBLE, $settings->statisticalScope());
        $this->assertSame([], $settings->rejectedValidationStatuses());

        // Avec ALL_VISIBLE, le filterStatistical ne retire rien.
        $items = collect([
            (object) ['statut_validation' => ActionTrackingService::VALIDATION_REJETEE_CHEF],
            (object) ['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION],
        ]);
        $this->assertCount(2, $settings->filterStatistical($items));
    }

    public function test_filter_statistical_keeps_only_direction_validated_by_default(): void
    {
        /** @var ActionCalculationSettings $settings */
        $settings = app(ActionCalculationSettings::class);

        $items = collect([
            (object) ['statut_validation' => ActionTrackingService::VALIDATION_REJETEE_CHEF],
            (object) ['statut_validation' => ActionTrackingService::VALIDATION_REJETEE_DIRECTION],
            (object) ['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION],
            (object) ['statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF],
        ]);

        $kept = $settings->filterStatistical($items);
        $this->assertCount(1, $kept);
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, $kept[0]->statut_validation);
    }

    /**
     * @return array{0:User,1:Pta}
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-RAJ',
            'libelle' => 'Direction Reporting',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-RAJ',
            'libelle' => 'Service Reporting',
            'actif' => true,
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'direction_id' => null,
            'service_id' => null,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS Filtre',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'en_cours',
        ]);

        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-FILT',
            'libelle' => 'Axe Filtre',
            'ordre' => 1,
        ]);

        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-FILT',
            'libelle' => 'Objectif Filtre',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO Filtre',
            'statut' => 'valide',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Filtre',
            'statut' => 'valide',
        ]);

        return [$admin, $pta];
    }
}
