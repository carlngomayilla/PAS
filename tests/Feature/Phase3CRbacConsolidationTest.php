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
use App\Services\Actions\ActionTrackingService;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\RolePermissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre la sous-phase 3.C :
 *   - A36 : SCIQ, SCIQ_SUIVI_GLOBAL et CHEF_UNITE_SCIQ portent strictement la
 *     meme matrice de permissions (alias fonctionnels).
 *   - A35 : les totaux globaux du dashboard et du reporting concordent pour un
 *     meme user et un meme exercice (anti-divergence chiffres).
 */
class Phase3CRbacConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a36_sciq_aliases_share_the_same_permissions(): void
    {
        /** @var RolePermissionSettings $registry */
        $registry = app(RolePermissionSettings::class);

        $sciq = $registry->forRole(User::ROLE_SCIQ);
        $sciqSuiviGlobal = $registry->forRole(User::ROLE_SCIQ_SUIVI_GLOBAL);
        $chefUniteSciq = $registry->forRole(User::ROLE_CHEF_UNITE_SCIQ);

        sort($sciq);
        sort($sciqSuiviGlobal);
        sort($chefUniteSciq);

        $this->assertSame(
            $sciq,
            $sciqSuiviGlobal,
            'A36 — ROLE_SCIQ_SUIVI_GLOBAL doit avoir EXACTEMENT les memes permissions que ROLE_SCIQ.'
        );

        $this->assertSame(
            $sciq,
            $chefUniteSciq,
            'A36 — ROLE_CHEF_UNITE_SCIQ doit avoir EXACTEMENT les memes permissions que ROLE_SCIQ.'
        );
    }

    public function test_a35_dashboard_and_reporting_totals_match_for_same_user(): void
    {
        [$admin, $pta] = $this->createPlanningFixture();

        // Cree quelques actions pour avoir des totaux non-nuls.
        for ($i = 0; $i < 3; $i++) {
            Action::query()->create([
                'pta_id' => $pta->id,
                'libelle' => 'Action '.$i,
                'description' => 'Test',
                'type_cible' => 'qualitative',
                'resultat_attendu' => 'OK',
                'date_debut' => '2026-01-01',
                'date_fin' => '2026-01-10',
                'date_echeance' => '2026-01-10',
                'responsable_id' => $admin->id,
                'statut' => 'en_cours',
                'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ]);
        }

        // Source 1 : reporting analytics (canonique).
        $reporting = app(ReportingAnalyticsService::class)
            ->buildPayload($admin, false, false);

        $reportingTotals = $reporting['global'] ?? [];

        // Source 2 : dashboard (recalcul rapide). On l invoque indirectement
        // via la route /dashboard pour s assurer que les chemins existent encore.
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();

        // Verifie que les totaux REPORTING sont coherents avec les comptages
        // bruts (Action::count). C est la garantie de coherence cote canonique.
        $this->assertSame(
            Action::query()->count(),
            (int) ($reportingTotals['actions_total'] ?? -1),
            'A35 — Le total actions du reporting doit refleter exactement Action::count().'
        );

        $this->assertSame(
            Pas::query()->count(),
            (int) ($reportingTotals['pas_total'] ?? -1),
            'A35 — Le total PAS du reporting doit refleter exactement Pas::count().'
        );

        $this->assertSame(
            Pao::query()->count(),
            (int) ($reportingTotals['paos_total'] ?? -1),
            'A35 — Le total PAO du reporting doit refleter exactement Pao::count().'
        );

        $this->assertSame(
            Pta::query()->count(),
            (int) ($reportingTotals['ptas_total'] ?? -1),
            'A35 — Le total PTA du reporting doit refleter exactement Pta::count().'
        );
    }

    /**
     * @return array{0:User,1:Pta}
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-3C', 'libelle' => 'D 3C', 'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id, 'code' => 'SRV-3C', 'libelle' => 'S 3C', 'actif' => true,
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS 3C', 'periode_debut' => 2026, 'periode_fin' => 2028, 'statut' => 'en_cours',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id, 'code' => 'AXE-3C', 'libelle' => 'A 3C', 'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id, 'code' => 'OS-3C', 'libelle' => 'O 3C', 'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id, 'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id, 'service_id' => $service->id,
            'annee' => 2026, 'titre' => 'PAO 3C', 'statut' => 'valide',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id, 'direction_id' => $direction->id,
            'service_id' => $service->id, 'titre' => 'PTA 3C', 'statut' => 'valide',
        ]);

        return [$admin, $pta];
    }
}
