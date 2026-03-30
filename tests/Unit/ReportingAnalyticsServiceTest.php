<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_consolidation_progression_only_uses_direction_validated_actions(): void
    {
        [$admin, $pta] = $this->createPlanningFixture();

        Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action validee',
            'description' => 'Action validee direction',
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
            'libelle' => 'Action non validee',
            'description' => 'Action encore non consolidee',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'Livrer',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $admin->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'progression_reelle' => 20,
            'progression_theorique' => 20,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
        ]);

        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, false, false);

        $this->assertSame(1, $payload['global']['actions_validees']);
        $this->assertCount(1, $payload['pasConsolidation']);
        $this->assertSame(80.0, (float) $payload['pasConsolidation'][0]['progression_moyenne']);
        $this->assertCount(1, $payload['interannualComparison']);
        $this->assertSame(80.0, (float) $payload['interannualComparison'][0]['progression_moyenne']);
    }

    /**
     * @return array{0:User,1:Pta}
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-RA',
            'libelle' => 'Direction Reporting Analytics',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-RA',
            'libelle' => 'Service Reporting Analytics',
            'actif' => true,
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'direction_id' => null,
            'service_id' => null,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS Consolidation',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'en_cours',
        ]);

        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-RA',
            'libelle' => 'Axe Reporting',
            'ordre' => 1,
        ]);

        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-RA',
            'libelle' => 'Objectif Reporting',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO Reporting',
            'statut' => 'valide',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Reporting',
            'statut' => 'valide',
        ]);

        return [$admin, $pta];
    }
}
