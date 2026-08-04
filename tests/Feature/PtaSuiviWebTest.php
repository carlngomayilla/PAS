<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\DeletionRequest;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\PlatformSetting;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Exports\PtaEvolutionWorkbookExporter;
use App\Services\Exports\PtaSuiviWorkbookExporter;
use App\Services\PtaSuiviService;
use App\Services\RolePermissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use ZipArchive;

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
            ->assertSee('Aucune action PTA', false)
            ->assertSee('>Synthèse</a>', false)
            ->assertSee('onclick="window.print()"', false)
            ->assertDontSee('id="admin-sidebar-open"', false);
    }

    public function test_pta_suivi_displays_all_pas_axes_even_without_actions(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $hierarchy = $this->makePasHierarchyWithEmptyAxis();

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee($hierarchy['active_axis']->libelle)
            ->assertSee($hierarchy['empty_axis']->libelle)
            ->assertSee($hierarchy['empty_objective']->libelle);
    }

    public function test_planning_control_profile_can_inline_update_pta_suivi_action(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $rmo = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'name' => 'RMO inline action',
        ]);
        $action = $this->makePtaAction('Action inline avant', '2026-12-15');

        $this->actingAs($user)
            ->patch(route('pta.suivi.actions.update', [
                'action' => $action,
                'annee' => 'all',
                'periode' => 't4',
            ]), [
                'row_type' => 'action',
                'libelle' => 'Action inline modifiee',
                'type_indicateur' => 'quantitatif',
                'indicateur' => 'Nombre de dossiers traites',
                'quantite_a_realiser' => '42',
                'seuil_minimum' => '85',
                'unite' => 'dossiers',
                'rmo_id' => $rmo->id,
                'observations' => 'Observation inline',
            ])
            ->assertRedirect(route('pta.suivi.index', [
                'annee' => 'all',
                'periode' => 't4',
            ]));

        $action->refresh();

        $this->assertSame('Action inline modifiee', $action->libelle);
        $this->assertSame('Nombre de dossiers traites', $action->indicateurs_attendus);
        $this->assertNull($action->cible);
        $this->assertSame('dossiers', $action->unite_cible);
        $this->assertSame((int) $rmo->id, (int) $action->responsable_id);
        $this->assertDatabaseHas('action_responsables', [
            'action_id' => $action->id,
            'user_id' => $rmo->id,
            'is_primary' => true,
        ]);
        $this->assertSame('Observation inline', $action->observations);
        $this->assertSame(42.0, (float) $action->quantite_a_realiser);
        $this->assertSame(85.0, (float) $action->seuil_minimum);
        $this->assertSame('2026-01-01', $action->date_debut?->toDateString());
        $this->assertSame('2026-12-15', $action->date_fin?->toDateString());
    }

    public function test_pta_suivi_action_header_update_without_threshold_preserves_existing_threshold(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action seuil preserve', '2026-12-15', [
            'type_indicateur' => 'quantitatif',
            'indicateurs_attendus' => 'Dossiers traites',
            'quantite_a_realiser' => 30,
            'quantite_cible' => 30,
            'seuil_minimum' => 65.5,
            'unite_cible' => 'dossiers',
        ]);

        $this->actingAs($user)
            ->patch(route('pta.suivi.actions.update', $action), [
                'row_type' => 'action',
                'libelle' => 'Action seuil preserve modifiee',
                'type_indicateur' => 'quantitatif',
                'indicateur' => 'Dossiers traites',
                'quantite_a_realiser' => '30',
                'unite' => 'dossiers',
                'observations' => 'Sans submit du seuil',
            ])
            ->assertRedirect(route('pta.suivi.index'));

        $action->refresh();

        $this->assertSame('Action seuil preserve modifiee', $action->libelle);
        $this->assertSame(65.5, (float) $action->seuil_minimum);
    }

    public function test_even_controller_cannot_change_action_dates_directly_from_pta_suivi(): void
    {
        $controller = User::factory()->create([
            'role' => User::ROLE_SCIQ,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action date verrouillee', '2026-12-15');

        $this->actingAs($controller)
            ->patch(route('pta.suivi.actions.update', $action), [
                'row_type' => 'action',
                'libelle' => $action->libelle,
                'type_indicateur' => 'quantitatif',
                'indicateur' => 'Dossiers traites',
                'quantite_a_realiser' => 10,
                'unite' => 'dossiers',
                'date_fin' => '2027-03-31',
            ])
            ->assertSessionHasErrors('date_fin');

        $this->assertSame('2026-12-15', $action->fresh()->date_fin?->toDateString());
    }

    public function test_composed_action_can_be_fully_parameterized_from_its_parent_table_cell(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action composee a parametrer', '2026-12-15');
        $action->forceFill([
            'type_action' => Action::TYPE_COMPOSEE,
            'type_indicateur' => 'mixte',
            'seuil_minimum' => 75,
        ])->save();

        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $user->id,
            'libelle' => 'Sous-action existante',
            'type_indicateur' => 'non_quantitatif',
            'sub_action_type' => SousAction::TYPE_NON_QUANTITATIVE,
            'resultat_attendu' => 'Livrable initial',
            'livrable_attendu' => 'Livrable initial',
            'date_debut' => '2026-01-15',
            'date_fin' => '2026-12-15',
            'statut' => 'non_demarre',
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertDontSee('pta-inline-'.$action->id.'-action-header', false)
            ->assertDontSee('Sélectionner pour paramétrer l’action directement.', false)
            ->assertDontSee('data-pta-param-cancel', false)
            ->assertDontSee('Enregistrer l’action', false)
            ->assertSee('pta-row-actions-heading', false);

        $this->actingAs($user)
            ->patch(route('pta.suivi.actions.update', $action), [
                'row_type' => 'action',
                'libelle' => 'Action composee parametree dans le tableau',
                'type_indicateur' => 'mixte',
                'indicateur' => 'Dossiers traites et rapport transmis',
                'livrable_attendu' => 'Rapport de traitement signe',
                'quantite_a_realiser' => 25,
                'seuil_minimum' => 85,
                'unite' => 'dossiers',
                'rmo_id' => $user->id,
                'observations' => 'Parametrage realise directement dans le Suivi PTA.',
            ])
            ->assertRedirect(route('pta.suivi.index'));

        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'libelle' => 'Action composee parametree dans le tableau',
            'type_indicateur' => 'mixte',
            'quantite_a_realiser' => 25,
            'seuil_minimum' => 85,
            'unite_cible' => 'dossiers',
            'responsable_id' => $user->id,
            'livrable_attendu' => 'Rapport de traitement signe',
        ]);
    }

    public function test_sciq_profile_can_inline_update_saved_locked_pta_suivi_action(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SCIQ,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action verrouillee avant', '2026-12-15');
        $action->forceFill([
            'statut_parametrage' => 'parametre',
            'modification_locked_at' => now(),
            'modification_unlocked_at' => null,
            'modification_unlock_expires_at' => null,
        ])->save();

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertDontSee('data-pta-param-editor', false)
            ->assertDontSee('name="type_indicateur"', false)
            ->assertDontSee('name="indicateur"', false)
            ->assertDontSee('Faire le suivi', false)
            ->assertDontSee("Report d'échéance", false)
            ->assertSee('data-pta-action-open', false)
            ->assertDontSee('href="'.route('pta.suivi.details', $action).'"', false);

        $this->actingAs($user)
            ->patch(route('pta.suivi.actions.update', $action), [
                'row_type' => 'action',
                'libelle' => 'Action verrouillee modifiee',
                'type_indicateur' => 'quantitatif',
                'indicateur' => 'Dossiers controles',
                'quantite_a_realiser' => '10',
                'seuil_minimum' => '90',
                'unite' => 'dossiers',
                'observations' => 'Ajustement SCIQ',
            ])
            ->assertRedirect(route('pta.suivi.index'));

        $action->refresh();

        $this->assertSame('Action verrouillee modifiee', $action->libelle);
        $this->assertSame('Dossiers controles', $action->indicateurs_attendus);
        $this->assertSame(10.0, (float) $action->quantite_a_realiser);
        $this->assertSame(90.0, (float) $action->seuil_minimum);
        $this->assertSame('Ajustement SCIQ', $action->observations);
    }

    public function test_planning_and_sciq_profiles_can_always_use_inline_controls_without_extra_permission(): void
    {
        $action = $this->makePtaAction('Action profils controle', '2026-12-15');

        foreach ([
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_CHEF_UNITE_SCIQ,
        ] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get(route('pta.suivi.index', ['annee' => 'all']))
                ->assertOk()
                ->assertDontSee('data-pta-param-editor', false)
                ->assertDontSee('name="type_indicateur"', false)
                ->assertDontSee('name="rmo_id"', false)
                ->assertDontSee('Faire le suivi', false)
                ->assertDontSee("Report d'échéance", false)
                ->assertDontSee(route('pta.suivi.actions.update', $action), false);

            $parameterUrl = route('workspace.pta.edit', $action->pta_id).'?focus=action#action-'.$action->id;
            $response = $this->actingAs($user)->get(route('pta.suivi.index', ['annee' => 'all']));
            if (in_array($role, [User::ROLE_PLANIFICATION, User::ROLE_CHEF_PLANIFICATION], true)) {
                $response
                    ->assertSee('Modifier le paramétrage', false)
                    ->assertSee($parameterUrl, false);
            } else {
                $response
                    ->assertDontSee('Modifier le paramétrage', false)
                    ->assertDontSee($parameterUrl, false);
            }

            $this->actingAs($user)
                ->patch(route('pta.suivi.actions.update', $action), [
                    'row_type' => 'action',
                    'libelle' => 'Action modifiee par '.$role,
                    'type_indicateur' => 'quantitatif',
                    'indicateur' => 'Dossiers ajustes',
                    'quantite_a_realiser' => '15',
                    'seuil_minimum' => '80',
                    'unite' => 'dossiers',
                    'observations' => 'Ajustement '.$role,
                ])
                ->assertRedirect(route('pta.suivi.index'));

            $action->refresh();

            $this->assertSame('Action modifiee par '.$role, $action->libelle);
            $this->assertSame('Dossiers ajustes', $action->indicateurs_attendus);
            $this->assertSame(15.0, (float) $action->quantite_a_realiser);
            $this->assertSame('Ajustement '.$role, $action->observations);
        }
    }

    public function test_planning_control_profile_can_inline_update_pta_suivi_sub_action_as_mixed_indicator(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action sous-action mixte', '2026-12-15');
        $rmo = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'name' => 'RMO sous action',
        ]);
        $subAction = SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $user->id,
            'libelle' => 'Sous-action avant',
            'resultat_attendu' => 'Ancien indicateur',
            'cible_prevue' => 5,
            'quantite_realisee' => 1,
            'unite' => 'dossiers',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'en_cours',
        ]);

        $this->actingAs($user)
            ->patch(route('pta.suivi.actions.update', $action), [
                'row_type' => 'sous_action',
                'sous_action_id' => $subAction->id,
                'libelle' => 'Sous-action mixte modifiee',
                'type_indicateur' => 'mixte',
                'indicateur' => 'Nombre et livrable attendus',
                'quantite_a_realiser' => '12',
                'seuil_minimum' => '70',
                'unite' => 'dossiers',
                'rmo_id' => $rmo->id,
                'livrable_attendu' => 'Livrable valide',
                'observations' => 'Observation sous-action',
            ])
            ->assertRedirect(route('pta.suivi.index'));

        $subAction->refresh();

        $this->assertSame('Sous-action mixte modifiee', $subAction->libelle);
        $this->assertSame('mixte', $subAction->type_indicateur);
        $this->assertSame(SousAction::TYPE_MIXTE, $subAction->sub_action_type);
        $this->assertSame('Nombre et livrable attendus', $subAction->resultat_attendu);
        $this->assertSame('Livrable valide', $subAction->cible);
        $this->assertSame('Livrable valide', $subAction->livrable_attendu);
        $this->assertSame(12.0, (float) $subAction->quantite_a_realiser);
        $this->assertSame(70.0, (float) $subAction->seuil_minimum);
        $this->assertSame('dossiers', $subAction->unite);
        $this->assertSame((int) $rmo->id, (int) $subAction->agent_id);
        $this->assertSame('Observation sous-action', $subAction->commentaire);
        $this->assertSame('2026-01-01', $subAction->date_debut?->toDateString());
        $this->assertSame('2026-12-15', $subAction->date_fin?->toDateString());
    }

    public function test_planning_control_profile_can_inline_delete_pta_suivi_sub_action(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action avec suppression sous-action', '2026-12-15');
        $subAction = SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $user->id,
            'libelle' => 'Sous-action a supprimer',
            'quantite_a_realiser' => 5,
            'cible_prevue' => 5,
            'quantite_realisee' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'en_cours',
        ]);

        $this->actingAs($user)
            ->delete(route('pta.suivi.actions.destroy', $action), [
                'row_type' => 'sous_action',
                'sous_action_id' => $subAction->id,
            ])
            ->assertRedirect(route('pta.suivi.index'));

        $this->assertSoftDeleted($subAction);
        $this->assertNotSoftDeleted($action);
    }

    public function test_planning_control_profile_can_inline_delete_pta_suivi_action(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action a supprimer inline', '2026-12-15');
        $subAction = SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $user->id,
            'libelle' => 'Sous-action liee',
            'quantite_a_realiser' => 5,
            'cible_prevue' => 5,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'en_cours',
        ]);

        $this->actingAs($user)
            ->delete(route('pta.suivi.actions.destroy', $action), [
                'row_type' => 'action',
            ])
            ->assertRedirect(route('pta.suivi.index'));

        $this->assertNotSoftDeleted($action);
        $this->assertNotSoftDeleted($subAction);
        $this->assertDatabaseHas('deletion_requests', [
            'entity_type' => Action::class,
            'entity_id' => $action->id,
            'status' => DeletionRequest::STATUS_PENDING,
        ]);
    }

    public function test_inline_action_update_can_return_json_without_page_reload(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action AJAX', '2026-12-15');

        $this->actingAs($user)
            ->patchJson(route('pta.suivi.actions.update', $action), [
                'row_type' => 'action',
                'libelle' => 'Action AJAX modifiée',
                'type_indicateur' => 'quantitatif',
                'indicateur' => 'Dossiers traités',
                'quantite_a_realiser' => 25,
                'seuil_minimum' => 80,
                'unite' => 'dossiers',
                'observations' => 'Sauvegarde asynchrone.',
            ])
            ->assertOk()
            ->assertJsonPath('action_id', $action->id)
            ->assertJsonPath('row_type', 'action');

        $this->assertSame('Action AJAX modifiée', $action->fresh()->libelle);
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
            ->assertSee('<th class="pta-row-actions-heading">Commandes</th>', false)
            ->assertSee('<th>RMO</th>', false)
            ->assertSee('<th>Seuil de complétude</th>', false)
            ->assertSee('<th>Performance</th>', false)
            ->assertDontSee('<th>Cible (seuil)</th>', false)
            ->assertDontSee('Performance en fonction de la cible', false)
            ->assertSee('style="background:#0f2f57;color:#ffffff;"', false)
            ->assertSee('.pta-level-sub-action td { background:#f1f5f9;', false)
            ->assertSee('.pta-sub-action-row td { background:#f1f5f9;', false)
            ->assertSee('.pta-hierarchy-action-cell { background:#f8fafc;', false)
            ->assertSee('pta-hierarchy-action-cell" style="background:#f8fafc;color:#111827;"', false)
            ->assertSee('pta-preview-link', false)
            ->assertSee('data-pta-action-open', false)
            ->assertDontSee('href="'.route('pta.suivi.details', $action).'"', false)
            ->assertDontSee(route('pta.suivi.details', $action), false)
            ->assertDontSee('pta-parameter-link', false)
            ->assertSee('pta-parameter-pill', false)
            ->assertDontSee('pta-inline-field', false)
            ->assertDontSee('name="rmo_id"', false)
            ->assertDontSee(route('pta.suivi.actions.update', $action), false)
            ->assertSee(route('workspace.pta.edit', $action->pta_id).'?focus=action#action-'.$action->id, false)
            ->assertSee('Modifier le paramétrage', false)
            ->assertDontSee('Faire le suivi', false)
            ->assertDontSee("Report d'échéance", false)
            ->assertDontSee('focus=target#action-', false)
            ->assertSee('.pta-hierarchy-number, .pta-objective-number { width:42px; }', false)
            ->assertSee('colspan="15" class="pta-pas-label"', false)
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
            'type_indicateur' => 'quantitatif',
            'sub_action_type' => SousAction::TYPE_QUANTITATIVE,
            'resultat_attendu' => 'Dossiers traites',
            'quantite_a_realiser' => 10,
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
            'type_indicateur' => 'mixte',
            'sub_action_type' => SousAction::TYPE_MIXTE,
            'resultat_attendu' => 'Dossiers valides',
            'livrable_attendu' => 'Dossiers valides signes',
            'quantite_a_realiser' => 5,
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
            'type_indicateur' => 'non_quantitatif',
            'sub_action_type' => SousAction::TYPE_NON_QUANTITATIVE,
            'resultat_attendu' => 'Quantite a definir',
            'quantite_a_realiser' => 0,
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
            ->assertDontSee(route('pta.suivi.details', $action), false)
            ->assertDontSee(route('pta.suivi.actions.update', $action), false)
            ->assertDontSee(route('pta.suivi.actions.destroy', $action), false)
            ->assertDontSee('report_sous_action_id=', false)
            ->assertDontSee('focus=sub_target&amp;sub_action_id=', false)
            ->assertDontSee('data-pta-param-editor', false)
            ->assertDontSee('data-pta-type-input', false)
            ->assertDontSee('data-pta-param-fields', false)
            ->assertDontSee('data-pta-type-continue', false)
            ->assertSee('Quantité à réaliser', false)
            ->assertDontSee('Livrable / resultat attendu', false)
            ->assertSee('Quantité à réaliser : 10 dossiers', false)
            ->assertSee('Quantité à réaliser : 5 dossiers', false)
            ->assertSee('Livrable attendu : Dossiers valides signes', false)
            ->assertDontSee('Seuil (%)', false)
            ->assertDontSee('name="rmo_id"', false)
            ->assertDontSee('name="seuil_minimum"', false)
            ->assertDontSee('Supprimer', false)
            ->assertSee('pta-threshold-card', false)
            ->assertDontSee('pta-param-threshold', false)
            ->assertDontSee('Seuil : 80%', false)
            ->assertDontSee('value="10"', false)
            ->assertDontSee('value="5"', false)
            ->assertSee('100%')
            ->assertSeeInOrder([
                'Action avec sous-actions detaillees',
                '1.</span>',
                'Collecter dossiers',
                'Dossiers traites',
                'Agent sous action',
                '2.</span>',
                'Valider dossiers',
                'Dossiers valides',
            ], false);
    }

    public function test_pta_suivi_indicator_cell_can_be_parameterized_when_indicator_is_empty(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);

        $this->makePtaAction('Action indicateur vide', '2026-12-15', [
            'type_indicateur' => 'quantitatif',
            'indicateurs_attendus' => null,
            'quantite_a_realiser' => 10,
            'quantite_cible' => 10,
            'unite_cible' => 'dossiers',
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('À renseigner', false)
            ->assertDontSee('data-pta-indicator-cell', false)
            ->assertDontSee('data-pta-param-fields', false)
            ->assertDontSee('aria-controls="pta-indicator-panel-', false)
            ->assertDontSee('pta-param-edit-affordance', false)
            ->assertSee('Quantité à réaliser : 10 dossiers', false)
            ->assertDontSee('name="type_indicateur"', false)
            ->assertDontSee('name="indicateur"', false)
            ->assertDontSee('type="hidden" name="libelle" value="Action indicateur vide"', false)
            ->assertDontSee('placeholder="Indicateur a renseigner"', false)
            ->assertDontSee('placeholder="Ex. 10"', false)
            ->assertDontSee('placeholder="Livrable attendu"', false)
            ->assertDontSee('data-pta-param-type-screen', false)
            ->assertDontSee('data-pta-type-continue', false)
            ->assertDontSee('name="cible"', false);
    }

    public function test_agent_can_open_scoped_pta_suivi_with_only_tracking_and_report_commands(): void
    {
        $action = $this->makePtaAction('Action agent visible', '2026-12-15');
        $action->loadMissing('pta');
        $user = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'direction_id' => $action->pta?->direction_id,
            'service_id' => $action->pta?->service_id,
        ]);
        $action->forceFill(['responsable_id' => $user->id])->save();

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('Action agent visible', false)
            ->assertDontSee('Faire le suivi', false)
            ->assertSee("Report d'échéance", false)
            ->assertDontSee(route('pta.suivi.actions.update', $action), false)
            ->assertDontSee('Fiche PTA', false);
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

    public function test_pta_suivi_excel_export_contains_sub_actions_and_indicator_details(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required to inspect XLSX contents.');
        }

        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action export detaillee', '2026-12-15', [
            'type_indicateur' => 'mixte',
        ]);

        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $user->id,
            'libelle' => 'Verifier les pieces',
            'type_indicateur' => 'mixte',
            'sub_action_type' => SousAction::TYPE_MIXTE,
            'resultat_attendu' => 'Pieces verifiees',
            'livrable_attendu' => 'PV signe',
            'quantite_a_realiser' => 3,
            'cible_prevue' => 3,
            'quantite_realisee' => 1,
            'unite' => 'dossiers',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-15',
            'statut' => 'en_cours',
        ]);

        $request = Request::create(route('pta.suivi.index'), 'GET', ['annee' => 'all']);
        $payload = app(PtaSuiviService::class)->buildPagePayload($request, $user);
        $path = app(PtaSuiviWorkbookExporter::class)->create($payload);

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            $this->assertStringContainsString('Sous-actions', $sheet);
            $this->assertStringContainsString('Verifier les pieces', $sheet);
            $this->assertStringContainsString('Type : Mixte', $sheet);
            $this->assertStringContainsString('Quantité à réaliser : 3 dossiers', $sheet);
            $this->assertStringContainsString('Livrable attendu : PV signe', $sheet);
            $this->assertStringNotContainsString('Progression</t>', $sheet);
        } finally {
            @unlink($path);
        }
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

    public function test_pta_suivi_filters_by_service_operational_objective(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $direction = Direction::query()->create([
            'code' => 'DIROO',
            'libelle' => 'Direction objectifs',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRVOO',
            'libelle' => 'Service objectifs',
            'actif' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS objectifs',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
        ]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-OO',
            'libelle' => 'Axe objectifs',
            'ordre' => 1,
        ]);
        $strategicObjective = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-OO',
            'libelle' => 'Objectif strategique objectifs',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO objectifs',
        ]);
        $firstObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'code' => 'OO-1',
            'libelle' => 'Objectif operationnel filtre',
            'echeance' => '2026-12-15',
        ]);
        $secondObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'code' => 'OO-2',
            'libelle' => 'Objectif operationnel masque',
            'echeance' => '2026-12-15',
        ]);
        $firstPta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $firstObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA objectif filtre',
        ]);
        $secondPta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $secondObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA objectif masque',
        ]);

        $this->makePtaAction('Action objectif visible', '2026-12-15', [
            'pta_id' => $firstPta->id,
            'objectif_operationnel_id' => $firstObjective->id,
        ]);
        $this->makePtaAction('Action objectif masque', '2026-12-15', [
            'pta_id' => $secondPta->id,
            'objectif_operationnel_id' => $secondObjective->id,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', [
                'annee' => 'all',
                'service_id' => $service->id,
                'objectif_operationnel_id' => $firstObjective->id,
            ]))
            ->assertOk()
            ->assertSee('Objectif operationnel filtre', false)
            ->assertSee('Action objectif visible', false)
            ->assertDontSee('Objectif operationnel masque', false)
            ->assertDontSee('Action objectif masque', false);

        $payload = app(PtaSuiviService::class)->buildPagePayload(
            Request::create(route('pta.suivi.index'), 'GET', [
                'annee' => 'all',
                'service_id' => $service->id,
                'objectif_operationnel_id' => $firstObjective->id,
            ]),
            $user
        );
        $operationalObjectiveLabels = collect($payload['groups'])
            ->flatMap(fn (array $pasGroup) => collect($pasGroup['axes'] ?? []))
            ->flatMap(fn (array $axisGroup) => collect($axisGroup['objectifs'] ?? []))
            ->flatMap(fn (array $strategicGroup) => collect($strategicGroup['objectifs_operationnels'] ?? []))
            ->pluck('label')
            ->values()
            ->all();

        $this->assertContains('OO-1 - Objectif operationnel filtre', $operationalObjectiveLabels);
        $this->assertNotContains('OO-2 - Objectif operationnel masque', $operationalObjectiveLabels);
        $this->assertNotContains('Aucun objectif operationnel rattache', $operationalObjectiveLabels);
    }

    public function test_pta_suivi_filter_options_keep_service_objectives_available_for_browser_scoping(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $firstDirection = Direction::query()->create([
            'code' => 'DIRF1',
            'libelle' => 'Direction filtre 1',
            'actif' => true,
        ]);
        $secondDirection = Direction::query()->create([
            'code' => 'DIRF2',
            'libelle' => 'Direction filtre 2',
            'actif' => true,
        ]);
        $firstService = Service::query()->create([
            'direction_id' => $firstDirection->id,
            'code' => 'SRVF1',
            'libelle' => 'Service filtre 1',
            'actif' => true,
        ]);
        $secondService = Service::query()->create([
            'direction_id' => $secondDirection->id,
            'code' => 'SRVF2',
            'libelle' => 'Service filtre 2',
            'actif' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS filtres dynamiques',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
        ]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-FLT',
            'libelle' => 'Axe filtres',
            'ordre' => 1,
        ]);
        $strategicObjective = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-FLT',
            'libelle' => 'Objectif strategique filtres',
            'ordre' => 1,
        ]);
        $firstPao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $firstDirection->id,
            'service_id' => $firstService->id,
            'annee' => 2026,
            'titre' => 'PAO filtre 1',
        ]);
        $secondPao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $secondDirection->id,
            'service_id' => $secondService->id,
            'annee' => 2026,
            'titre' => 'PAO filtre 2',
        ]);
        ObjectifOperationnel::query()->create([
            'pao_id' => $firstPao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $firstDirection->id,
            'service_id' => $firstService->id,
            'code' => 'OO-F1',
            'libelle' => 'Objectif filtre service 1',
            'echeance' => '2026-12-15',
        ]);
        ObjectifOperationnel::query()->create([
            'pao_id' => $secondPao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $secondDirection->id,
            'service_id' => $secondService->id,
            'code' => 'OO-F2',
            'libelle' => 'Objectif filtre service 2',
            'echeance' => '2026-12-15',
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', [
                'annee' => 'all',
                'direction_id' => $firstDirection->id,
            ]))
            ->assertOk()
            ->assertSee('data-direction="'.$firstDirection->id.'"', false)
            ->assertSee('data-direction="'.$secondDirection->id.'"', false)
            ->assertDontSee('data-service="'.$firstService->id.'"', false)
            ->assertDontSee('data-service="'.$secondService->id.'"', false)
            ->assertDontSee('syncPtaSuiviFilters', false)
            ->assertDontSee('updateObjectiveOptions', false)
            ->assertDontSee('option.hidden = !isAvailable', false)
            ->assertDontSee('option.disabled = !isAvailable', false);
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
            ->assertSee("Détail de l'action PTA", false)
            ->assertSee('Action detail modal', false)
            ->assertSee('Retard', false)
            ->assertSee('RMO', false)
            ->assertSee('Seuil', false)
            ->assertDontSee('Parametrer dans le PTA', false)
            ->assertDontSee(route('workspace.pta.edit', $action->pta_id), false)
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

    /**
     * Regle metier du 2026-08-04 : chaque action pese autant que les autres
     * dans son objectif operationnel, quelle que soit sa cible.
     *
     * 80/100 = 80 % et 10/20 = 50 % donnent (80 + 50) / 2 = 65 %.
     * L'ancienne ponderation par la cible aurait donne 90/120 = 75 %.
     */
    public function test_pta_suivi_rollup_gives_every_action_the_same_weight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 10:00:00'));

        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $largeAction = $this->makePtaAction('Action grande quantite', '2026-12-15', [
            'quantite_cible' => 100,
            'force' => ['quantite_realisee' => 80, 'progression_reelle' => 80],
        ]);
        $this->makePtaAction('Action petite quantite', '2026-12-15', [
            'pta_id' => $largeAction->pta_id,
            'quantite_cible' => 20,
            'force' => ['quantite_realisee' => 10, 'progression_reelle' => 50],
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('65%', false)
            ->assertDontSee('75%', false);
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
        $this->makePtaAction('Action sans quantite', '2026-12-15', [
            'pta_id' => $configuredAction->pta_id,
            'quantite_cible' => 0,
            'force' => ['quantite_realisee' => 100, 'progression_reelle' => 100],
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.index', ['annee' => 'all']))
            ->assertOk()
            ->assertSee('Action sans quantite', false)
            ->assertSee('À paramétrer', false)
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
        $this->assertStringContainsString('$cellPreviewMode = $isInteractive ? \'readonly\' : $exportMode;', $table);
        $this->assertStringContainsString('data-pta-action-open', $component);
        $this->assertStringContainsString('href="{{ $url }}"', $component);
        $this->assertStringContainsString('href="{{ $previewUrl }}"', $proofButton);
        $this->assertStringContainsString('display:block; width:100%;', $suiviView);
        $this->assertStringContainsString('text-decoration:none', $suiviView);
        $this->assertStringContainsString('box-shadow:none;', $suiviView);
        $this->assertStringNotContainsString("showIndicatorStep(editor, 'fields');", $suiviView);
        $this->assertStringNotContainsString('focusIndicatorEditor(editor);', $suiviView);
        $this->assertStringNotContainsString("cell?.querySelector('[data-pta-param-editor]')", $suiviView);
        $this->assertStringNotContainsString('data-pta-param-type-screen', $table);
        $this->assertStringNotContainsString('data-pta-type-continue', $table);
        $this->assertStringContainsString('pta-proof-count', $proofButton);
        $this->assertStringContainsString('name="rmo_id"', $table);
        $this->assertStringContainsString('<th>RMO</th>', $table);
        $this->assertStringContainsString('<th>Seuil de complétude</th>', $table);
        $this->assertStringContainsString('pta-row-actions', $table);
        $this->assertStringNotContainsString('Faire le suivi', $table);
        $this->assertStringContainsString("Report d'échéance", $table);
        $this->assertStringNotContainsString('name="date_fin" type="date"', $table);
        $this->assertStringContainsString('parameter_url', $table);
        $this->assertStringContainsString("route('workspace.pta.edit'", (string) file_get_contents(app_path('Services/PtaSuiviService.php')));
        $this->assertStringContainsString('canInlineEditAction', (string) file_get_contents(app_path('Http/Controllers/Web/PtaSuiviWebController.php')));
        $this->assertStringNotContainsString('Cible = seuil', $table);
        $this->assertStringNotContainsString('pta-parameter-pill', $table);
        $this->assertStringNotContainsString('<span class="pta-inline-actions">', $table);
        $this->assertStringNotContainsString('.pta-inline-actions', $suiviView);
        $this->assertStringContainsString('<x-pta.proof-modal />', $suiviView);
        $this->assertStringContainsString('[data-pta-action-open]', $suiviView);
        $this->assertStringContainsString('Previsualisation PTA', $modal);
        $this->assertStringNotContainsString('Parametrer dans le PTA', $detailsView);
        $this->assertStringNotContainsString("http_build_query(['focus' => 'action'])", (string) file_get_contents(app_path('Services/PtaSuiviService.php')));
        $this->assertStringContainsString('.pta-suivi-table tbody tr.pta-level-axis > td', $css);
        $this->assertStringContainsString('.pta-suivi-table th,', $css);
    }

    public function test_pta_suivi_eager_loads_action_logs_for_status_calculation(): void
    {
        $relationsMethod = new \ReflectionMethod(PtaSuiviService::class, 'actionRelations');
        $relations = $relationsMethod->invoke(app(PtaSuiviService::class));

        $this->assertContains(
            'actionLogs:id,action_id,type_evenement',
            $relations,
            'Le suivi PTA doit eviter une requete action_logs par ligne pendant le calcul des statuts.'
        );
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

    public function test_planning_control_profile_can_export_pta_evolution_report_pdf(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.export.evolution-pdf', ['annee' => 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_planning_control_profile_can_export_pta_evolution_report_excel(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pta.suivi.export.evolution-excel', ['annee' => 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * Le rapport d'evolution suit le modele institutionnel : un bloc par
     * objectif operationnel (axe, objectif strategique, objectif operationnel)
     * puis les neuf colonnes du tableau des actions detaillees.
     */
    /**
     * Le rapport d'evolution suit le modele institutionnel : detail par
     * direction et par service avec leurs responsables, puis un bloc par
     * objectif operationnel et les dix colonnes du tableau des actions.
     */
    public function test_pta_evolution_workbook_follows_the_institutional_template(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required to inspect XLSX contents.');
        }

        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Actualiser le tableau de conservation', '2026-04-15', [
            'ressources_necessaires' => ['ressources_humaines'],
            'ressources_details' => 'Juriste ANBG, personnel archives',
            'risque_potentiel' => 'Indisponibilite des agents',
            'indicateurs_attendus' => 'Tableau de conservation actualise',
            'quantite_a_realiser' => 100,
            'unite_cible' => 'dossiers',
        ]);

        $pta = $action->pta;
        User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'is_active' => true,
            'name' => 'Directrice de test',
            'direction_id' => $pta->direction_id,
        ]);
        User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'is_active' => true,
            'name' => 'Chef de service de test',
            'direction_id' => $pta->direction_id,
            'service_id' => $pta->service_id,
        ]);

        $request = Request::create(route('pta.suivi.index'), 'GET', ['annee' => 'all']);
        $payload = app(PtaSuiviService::class)->buildPagePayload($request, $user);
        $payload['directions'] = app(PtaSuiviService::class)->buildEvolutionReportGroups($payload['rows']);
        $path = app(PtaEvolutionWorkbookExporter::class)->create($payload);

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $styles = (string) $zip->getFromName('xl/styles.xml');
            $zip->close();

            foreach (PtaEvolutionWorkbookExporter::COLUMNS as $column) {
                $this->assertStringContainsString(
                    htmlspecialchars($column, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
                    $sheet,
                    "La colonne « {$column} » du modele institutionnel est absente du classeur."
                );
            }

            // Detail par direction et par service, avec les responsables.
            $this->assertStringContainsString('DIRECTION : ', $sheet);
            $this->assertStringContainsString('Directrice de test', $sheet);
            $this->assertStringContainsString('SERVICE : ', $sheet);
            $this->assertStringContainsString('Chef de service de test', $sheet);

            $this->assertStringContainsString('AXE STRAT', $sheet);
            $this->assertStringContainsString('OBJECTIF STRAT', $sheet);
            $this->assertStringContainsString('OBJECTIF OP', $sheet);
            $this->assertStringContainsString('Actualiser le tableau de conservation', $sheet);
            $this->assertStringContainsString('Ressources humaines, Juriste ANBG, personnel archives', $sheet);
            $this->assertStringContainsString('Indisponibilite des agents', $sheet);

            // Le livrable attendu est exprime en quantite + unite.
            $this->assertStringContainsString('100 dossiers', $sheet);

            // Le bleu du modele institutionnel est utilise pour les bandeaux.
            $this->assertStringContainsString('FF00B0F0', $styles);
        } finally {
            @unlink($path);
        }
    }

    public function test_pta_evolution_report_names_the_planning_service_chief(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $action = $this->makePtaAction('Action du service planification', '2026-06-30');
        $pta = $action->pta;

        // Le chef du service Planification porte le role chef_planification.
        User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
            'name' => 'Chef planification de test',
            'direction_id' => $pta->direction_id,
            'service_id' => $pta->service_id,
        ]);

        $request = Request::create(route('pta.suivi.index'), 'GET', ['annee' => 'all']);
        $service = app(PtaSuiviService::class);
        $payload = $service->buildPagePayload($request, $user);
        $directions = $service->buildEvolutionReportGroups($payload['rows']);

        $chiefs = collect($directions)
            ->flatMap(fn (array $direction): array => $direction['services'])
            ->pluck('chef')
            ->all();

        $this->assertContains('Chef planification de test', $chiefs);
    }

    public function test_reporting_pta_view_uses_official_suivi_component(): void
    {
        $view = (string) file_get_contents(resource_path('views/workspace/monitoring/reporting.blade.php'));

        $this->assertStringContainsString('reporting-pta-official', $view);
        $this->assertStringContainsString("@include('components.tables.pta-suivi-table'", $view);
        $this->assertStringContainsString("'exportMode' => 'web'", $view);
        $this->assertStringContainsString('dashboardTab=overview', $view);
    }

    /**
     * @return array<string, mixed>
     */
    private function makePasHierarchyWithEmptyAxis(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIRAXE',
            'libelle' => 'Direction axes',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRVAXE',
            'libelle' => 'Service axes',
            'actif' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS Axes complets',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
        ]);
        $activeAxis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-ACTIF',
            'libelle' => 'Axe avec action visible',
            'ordre' => 1,
        ]);
        $emptyAxis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-VIDE',
            'libelle' => 'Axe sans action visible',
            'ordre' => 2,
        ]);
        $activeObjective = PasObjectif::query()->create([
            'pas_axe_id' => $activeAxis->id,
            'code' => 'OS-ACTIF',
            'libelle' => 'Objectif avec action',
            'ordre' => 1,
        ]);
        $emptyObjective = PasObjectif::query()->create([
            'pas_axe_id' => $emptyAxis->id,
            'code' => 'OS-VIDE',
            'libelle' => 'Objectif sans action visible',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $activeObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO axes',
        ]);
        $operationalObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $activeAxis->id,
            'pas_objectif_id' => $activeObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'code' => 'OO-ACTIF',
            'libelle' => 'Objectif operationnel actif',
            'echeance' => '2026-12-15',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA axes',
        ]);

        $this->makePtaAction('Action axe visible', '2026-12-15', [
            'pta_id' => $pta->id,
            'objectif_operationnel_id' => $operationalObjective->id,
        ]);

        return [
            'active_axis' => $activeAxis,
            'empty_axis' => $emptyAxis,
            'empty_objective' => $emptyObjective,
        ];
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
