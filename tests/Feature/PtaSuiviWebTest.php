<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PlatformSetting;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
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

    public function test_pta_suivi_control_table_uses_hierarchy_colors_without_legend_or_progression_column(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action structure PTA', '2026-12-15', [
            'quantite_cible' => 0,
            'force' => ['quantite_realisee' => 0, 'progression_reelle' => 0],
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('pta-level-axis', false)
            ->assertSee('pta-level-strategic-objective', false)
            ->assertSee('pta-level-operational-objective', false)
            ->assertSee('<th>Sous-actions</th>', false)
            ->assertSee('style="background:#0f2f57;color:#ffffff;"', false)
            ->assertSee('.pta-level-sub-action td { background:#f1f5f9;', false)
            ->assertSee('.pta-sub-action-row td { background:#f1f5f9;', false)
            ->assertSee('.pta-hierarchy-action-cell { background:#f8fafc;', false)
            ->assertSee('pta-hierarchy-action-cell" style="background:#f8fafc;color:#111827;"', false)
            ->assertSee('pta-preview-link', false)
            ->assertSee('data-pta-action-open', false)
            ->assertSee('href="'.route('pta.suivi.details', $action).'"', false)
            ->assertSee(route('pta.suivi.details', $action), false)
            ->assertDontSee('pta-parameter-link', false)
            ->assertSee('pta-parameter-pill', false)
            ->assertSee('focus=action#action-', false)
            ->assertDontSee('focus=target#action-', false)
            ->assertSee('.pta-hierarchy-number, .pta-objective-number { width:42px; }', false)
            ->assertSee('colspan="14" class="pta-pas-label"', false)
            ->assertSee('colspan="7" class="pta-hierarchy-title"', false)
            ->assertDontSee('pta-suivi-legend', false)
            ->assertDontSee('Legende', false)
            ->assertDontSee('<th>Progression</th>', false)
            ->assertDontSee('pta-progress', false);
    }

    public function test_pta_suivi_displays_numbered_sub_action_rows_with_their_own_metrics(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'name' => 'Agent sous action',
        ]);
        $action = $this->makePtaAction('Action avec sous-actions detaillees', '2026-12-15');

        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $agent->id,
            'libelle' => 'Collecter dossiers',
            'resultat_attendu' => 'Dossiers traites',
            'cible_prevue' => 10,
            'quantite_realisee' => 4,
            'unite' => 'dossiers',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'en_cours',
        ]);
        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $agent->id,
            'libelle' => 'Valider dossiers',
            'resultat_attendu' => 'Dossiers valides',
            'cible_prevue' => 5,
            'quantite_realisee' => 5,
            'unite' => 'dossiers',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'effectuee',
            'est_effectuee' => true,
            'validation_status' => SousAction::VALIDATION_VALIDEE,
        ]);
        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $agent->id,
            'libelle' => 'Parametrer dossiers',
            'resultat_attendu' => 'Cible a definir',
            'cible_prevue' => 0,
            'quantite_realisee' => 0,
            'unite' => 'dossiers',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'en_cours',
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('<th>Sous-actions</th>', false)
            ->assertSee('pta-action-index-cell pta-hierarchy-action-cell', false)
            ->assertSee('pta-hierarchy-sub-action-cell" style="background:#f1f5f9;color:#334155;"', false)
            ->assertSee('pta-preview-link', false)
            ->assertSee(route('pta.suivi.details', $action), false)
            ->assertDontSee('focus=sub_target&amp;sub_action_id=', false)
            ->assertSeeInOrder([
                'Action avec sous-actions detaillees',
                '1.</span>',
                'Collecter dossiers',
                'Dossiers traites',
                'Agent sous action',
                '4 / 10',
                '10 dossiers',
                '4 dossiers',
                '40.00%',
                '2.</span>',
                'Valider dossiers',
                'Dossiers valides',
                '5 / 5',
                '100.00%',
            ], false);
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
            ->assertSee("Detail de l'action PTA", false)
            ->assertSee('Action detail modal', false)
            ->assertSee('Retard', false)
            ->assertSee('Parametrer dans le PTA', false)
            ->assertSee('href="'.route('workspace.pta.edit', $action->pta_id).'?focus=action#action-'.$action->id.'"', false)
            ->assertSee(route('workspace.actions.suivi', $action), false)
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

    public function test_pta_suivi_uses_target_weighted_rollups_instead_of_simple_average(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 10:00:00'));

        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $largeAction = $this->makePtaAction('Action grande cible', '2026-12-15', [
            'quantite_cible' => 100,
            'force' => ['quantite_realisee' => 80, 'progression_reelle' => 80],
        ]);
        $this->makePtaAction('Action petite cible', '2026-12-15', [
            'pta_id' => $largeAction->pta_id,
            'quantite_cible' => 20,
            'force' => ['quantite_realisee' => 10, 'progression_reelle' => 50],
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('75.00%', false)
            ->assertDontSee('65.00%', false);
    }

    public function test_pta_suivi_marks_zero_target_as_to_configure_and_excludes_it_from_rollup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 10:00:00'));

        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $configuredAction = $this->makePtaAction('Action parametree', '2026-12-15', [
            'quantite_cible' => 100,
            'force' => ['quantite_realisee' => 50, 'progression_reelle' => 50],
        ]);
        $this->makePtaAction('Action sans cible', '2026-12-15', [
            'pta_id' => $configuredAction->pta_id,
            'quantite_cible' => 0,
            'force' => ['quantite_realisee' => 100, 'progression_reelle' => 100],
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('Action sans cible', false)
            ->assertSee('A parametrer', false)
            ->assertSee('Performance consolidee : 50.00%', false);
    }

    public function test_pta_suivi_displays_disabled_proof_button_when_no_proof_exists(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $this->makePtaAction('Action sans preuve', '2026-12-15');

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('Aucune preuve', false);
    }

    public function test_pta_suivi_displays_active_proof_button_for_sub_action_proof(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action avec preuve sous-action', '2026-12-15');
        $sousAction = SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $user->id,
            'libelle' => 'Sous-action prouvee',
            'cible_prevue' => 100,
            'quantite_realisee' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'effectuee',
            'est_effectuee' => true,
        ]);
        $proof = Justificatif::query()->create([
            'justifiable_type' => Action::class,
            'justifiable_id' => $action->id,
            'sous_action_id' => $sousAction->id,
            'categorie' => 'sous_action',
            'nom_original' => 'preuve.pdf',
            'chemin_stockage' => 'justificatifs/preuve.pdf',
            'mime_type' => 'application/pdf',
            'taille_octets' => 128,
            'ajoute_par' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('aria-label="Previsualiser la preuve', false)
            ->assertSee('pta-proof-button-label">Preuve</span>', false)
            ->assertSee('data-preview-file', false)
            ->assertSee('href="'.route('workspace.actions.justificatifs.preview', [$action, $proof]).'"', false)
            ->assertSee(route('workspace.actions.justificatifs.preview', [$action, $proof]), false)
            ->assertSee(route('workspace.actions.justificatifs.download', [$action, $proof]), false)
            ->assertSee('pta-proof-count">1</span>', false);
    }

    public function test_pta_suivi_uses_contextual_preview_component_for_cells(): void
    {
        $table = (string) file_get_contents(resource_path('views/components/tables/pta-suivi-table.blade.php'));
        $component = (string) file_get_contents(resource_path('views/components/pta/preview-link.blade.php'));
        $proofButton = (string) file_get_contents(resource_path('views/components/pta/proof-button.blade.php'));
        $modal = (string) file_get_contents(resource_path('views/components/pta/proof-modal.blade.php'));
        $suiviView = (string) file_get_contents(resource_path('views/workspace/pta-suivi/index.blade.php'));
        $detailsView = (string) file_get_contents(resource_path('views/workspace/pta-suivi/partials/details.blade.php'));
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('<x-pta.preview-link', $table);
        $this->assertStringContainsString('data-pta-action-open', $component);
        $this->assertStringContainsString('href="{{ $url }}"', $component);
        $this->assertStringContainsString('href="{{ $previewUrl }}"', $proofButton);
        $this->assertStringContainsString('display:block; width:100%;', $suiviView);
        $this->assertStringContainsString('text-decoration:none', $suiviView);
        $this->assertStringContainsString('box-shadow:none;', $suiviView);
        $this->assertStringContainsString('pta-proof-count', $proofButton);
        $this->assertStringContainsString('pta-parameter-pill', $table);
        $this->assertStringContainsString('Previsualisation PTA', $modal);
        $this->assertStringContainsString('Parametrer dans le PTA', $detailsView);
        $this->assertStringContainsString("http_build_query(['focus' => 'action'])", (string) file_get_contents(app_path('Services/PtaSuiviService.php')));
        $this->assertStringContainsString('.pta-suivi-table tbody tr.pta-level-axis > td', $css);
        $this->assertStringContainsString('.pta-suivi-table th,', $css);
        $this->assertStringNotContainsString('workspace.pta.edit', $table);
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
        $this->assertStringContainsString('export-mode="web"', $view);
        $this->assertStringContainsString('dashboardTab=overview', $view);
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

        $actionOverrides = $overrides;
        unset($actionOverrides['pta_id'], $actionOverrides['force']);

        $action = Action::query()->create(array_merge([
            'pta_id' => $ptaId,
            'libelle' => $label,
            'date_debut' => '2026-01-01',
            'date_fin' => $deadline,
            'date_echeance' => $deadline,
            'indicateurs_attendus' => 'Indicateur global',
            'quantite_cible' => 100,
            'observations' => 'Observation test',
        ], $actionOverrides));

        $action->forceFill(array_merge([
            'statut_dynamique' => 'en_cours',
            'statut_validation' => 'non_soumise',
            'quantite_realisee' => 25,
            'progression_reelle' => 25,
        ], (array) ($overrides['force'] ?? [])))->save();

        return $action->refresh();
    }
}
