<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PlatformSetting;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\RolePermissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PtaSuiviWebTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_planning_control_profile_can_open_pta_suivi_page(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('SUIVI PTA', false)
            ->assertSee('S1', false)
            ->assertSee('Janvier', false)
            ->assertSee('Aucune action PTA', false);
    }

    public function test_agent_cannot_open_pta_suivi_page(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index'))
            ->assertForbidden();
    }

    public function test_planning_control_profile_can_export_pta_suivi_excel(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.export.excel', ['annee' => 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_pta_suivi_filters_monthly_period_and_delay_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 10:00:00'));

        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);

        $lateAction = $this->makePtaAction('Action Fevrier en retard', '2026-02-15');
        $futureAction = $this->makePtaAction('Action Decembre future', '2026-12-15', [
            'pta_id' => $lateAction->pta_id,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', [
                'annee' => 2026,
                'periode' => 'm2',
                'statut_delai' => 'hors_delai',
            ]))
            ->assertOk()
            ->assertSee('Action Fevrier en retard', false)
            ->assertSee('Retard', false)
            ->assertDontSee('Action Decembre future', false);
    }

    public function test_planning_control_profile_can_open_pta_suivi_details_modal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 10:00:00'));

        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action detail modal', '2026-02-15');

        $this->actingAs($user)
            ->get(route('pta.suivi.details', $action))
            ->assertOk()
            ->assertSee('Action detail modal', false)
            ->assertSee('Retard', false)
            ->assertSee("Parcours de l'action", false)
            ->assertSee('Validations', false);
    }

    public function test_pta_suivi_details_refuse_action_outside_user_scope(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'role_permissions', 'key' => 'role_permissions_'.User::ROLE_SERVICE],
            ['value' => json_encode(['planning.read', 'pta.control'], JSON_UNESCAPED_SLASHES)]
        );
        app(RolePermissionSettings::class)->flush();

        $direction = Direction::query()->create([
            'code' => 'USR',
            'libelle' => 'Direction utilisateur',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'USV',
            'libelle' => 'Service utilisateur',
            'actif' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $action = $this->makePtaAction('Action hors perimetre', '2026-02-15');

        $this->actingAs($user)
            ->get(route('pta.suivi.details', $action))
            ->assertForbidden();
    }

    public function test_invalid_pta_suivi_status_filters_are_ignored(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $this->makePtaAction('Action visible malgre filtre invalide', '2026-02-15');

        $this->actingAs($user)
            ->get(route('pta.suivi.index', [
                'annee' => 'all',
                'statut_suivi' => 'statut_inconnu',
                'statut_delai' => 'delai_inconnu',
                'alerte_echeance' => 'alerte_inconnue',
            ]))
            ->assertOk()
            ->assertSee('Action visible malgre filtre invalide', false);
    }

    public function test_planning_control_profile_can_export_pta_suivi_pdf(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.export.pdf', ['annee' => 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_reporting_pta_view_uses_official_suivi_component(): void
    {
        $view = (string) file_get_contents(resource_path('views/workspace/monitoring/reporting.blade.php'));

        $this->assertStringContainsString('reporting-pta-official', $view);
        $this->assertStringContainsString('<x-tables.pta-suivi-table', $view);
        $this->assertStringContainsString('export-mode="readonly"', $view);
    }

    private function makePtaAction(string $label, string $deadline, array $overrides = []): Action
    {
        $ptaId = $overrides['pta_id'] ?? null;
        if ($ptaId === null) {
            $direction = Direction::query()->create([
                'code' => 'DIR',
                'libelle' => 'Direction test',
                'actif' => true,
            ]);
            $service = Service::query()->create([
                'direction_id' => $direction->id,
                'code' => 'SRV',
                'libelle' => 'Service test',
                'actif' => true,
            ]);
            $pas = Pas::query()->create([
                'titre' => 'PAS Test',
                'periode_debut' => 2026,
                'periode_fin' => 2026,
            ]);
            $pao = Pao::query()->create([
                'pas_id' => $pas->id,
                'direction_id' => $direction->id,
                'annee' => 2026,
                'titre' => 'PAO Test',
                'objectif_operationnel' => 'Objectif operationnel test',
            ]);
            $pta = Pta::query()->create([
                'pao_id' => $pao->id,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'titre' => 'PTA Test',
            ]);
            $ptaId = (int) $pta->id;
        }

        $action = Action::query()->create([
            'pta_id' => $ptaId,
            'libelle' => $label,
            'date_debut' => '2026-01-01',
            'date_fin' => $deadline,
            'date_echeance' => $deadline,
            'indicateurs_attendus' => 'Indicateur global',
            'quantite_cible' => 100,
            'observations' => 'Observation test',
        ]);

        $action->forceFill([
            'statut_dynamique' => 'en_cours',
            'statut_validation' => 'non_soumise',
            'quantite_realisee' => 25,
            'progression_reelle' => 25,
        ])->save();

        return $action->refresh();
    }
}
