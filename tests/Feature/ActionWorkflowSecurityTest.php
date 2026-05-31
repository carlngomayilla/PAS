<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\WorkflowSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActionWorkflowSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_access_another_agent_action_from_web_or_api(): void
    {
        $fixture = $this->createActionFixture();
        $otherAgent = $fixture['other_agent'];
        $action = $fixture['action'];

        $this->actingAs($otherAgent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertForbidden();

        $token = $otherAgent->createToken('phpunit-agent-scope')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/actions/'.$action->id)
            ->assertForbidden();
    }

    public function test_agent_sees_quantitative_tracking_form_for_legacy_quantitative_action(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];

        $this->assertNull($action->mode_evaluation);

        $this->actingAs($agent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('quantite_realisee_action', false)
            ->assertSee('commentaire_quantitatif', false)
            ->assertSee('difficultes_quantitatives', false)
            ->assertSee('justificatif_quantitatif', false);
    }

    public function test_action_edit_redirects_to_pta_form_with_action_anchor(): void
    {
        // Regle metier ANBG : le formulaire standalone d'edition d'action est desactive
        // au profit du formulaire PTA qui expose le contexte complet (objectif operationnel,
        // sous-actions, responsables, etc.). L'URL workspace.actions.edit doit rediriger
        // vers workspace.pta.edit avec une ancre sur la section de l'action.
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $serviceUser = $fixture['service_user'];

        $this->actingAs($serviceUser)
            ->get(route('workspace.actions.edit', $action))
            ->assertRedirect(route('workspace.pta.edit', $action->pta_id).'#action-'.$action->id);

        // Le formulaire PTA cible doit lui-meme contenir le bloc d'edition de l'action.
        $this->actingAs($serviceUser)
            ->get(route('workspace.pta.edit', $action->pta_id))
            ->assertOk()
            ->assertSee('id="action-'.$action->id.'"', false)
            ->assertSee("1. Identification de l'action", false)
            ->assertSee('name="actions[0][ressources_necessaires][]"', false);
    }

    public function test_agent_marks_planned_sub_action_done_with_justificatif_and_optional_quantity(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
        ])->save();
        $action->weeks()->delete();

        $sousAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Verifier les dossiers transmis',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'cible_prevue' => 12,
            'unite' => 'dossiers',
            'quantite_realisee' => 0,
            'taux_realisation' => 0,
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('tracking-entry-form', false)
            ->assertSee('execution_only', false)
            ->assertSee('quantite_realisee_sous_action_'.$sousAction->id, false);

        $this->actingAs($agent)
            ->post(route('workspace.actions.sub-actions.update', [$action, $sousAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'est_effectuee' => '1',
                'quantite_realisee' => 12,
                'commentaire' => 'Sous-action realisee',
                'difficultes' => 'Aucune difficulte rencontree.',
                'justificatif' => UploadedFile::fake()->create('preuve-sous-action.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $sousAction->refresh();
        $this->assertTrue((bool) $sousAction->est_effectuee);
        $this->assertSame('12.0000', (string) $sousAction->quantite_realisee);
        $this->assertSame('100.00', (string) $sousAction->taux_execution);
        $this->assertDatabaseHas('justificatifs', [
            'sous_action_id' => $sousAction->id,
            'categorie' => 'sous_action',
        ]);
    }

    public function test_quantified_sub_action_submission_succeeds_without_comment(): void
    {
        // Workflow attendu : on enregistre/soumet d'abord, le commentaire est
        // toujours optionnel pour ne pas bloquer le RMO.
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
        ])->save();
        $action->weeks()->delete();

        $sousAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Preparer le dossier de preuve',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'cible_prevue' => 1,
            'unite' => 'dossier',
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $route = route('workspace.actions.sub-actions.update', [$action, $sousAction]);

        $this->actingAs($agent)
            ->from(route('workspace.actions.suivi', $action))
            ->post($route, [
                '_method' => 'PUT',
                'execution_only' => '1',
                'tracking_action' => 'submit',
                'est_effectuee' => '1',
                'quantite_realisee' => 1,
                'justificatif' => UploadedFile::fake()->create('preuve-commentaire.pdf', 64, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors('commentaire');

        $sousAction->refresh();
        $this->assertTrue((bool) $sousAction->est_effectuee);
        $this->assertSame('en_attente_validation_chef', $sousAction->statut);
    }

    public function test_non_quantified_sub_action_requires_proof_but_not_quantity(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
        ])->save();
        $action->weeks()->delete();

        $sousAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Rediger une note de cadrage',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $this->actingAs($agent)
            ->from(route('workspace.actions.suivi', $action))
            ->post(route('workspace.actions.sub-actions.update', [$action, $sousAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'commentaire' => 'Note preparee.',
                'difficultes' => 'Aucune difficulte rencontree.',
            ])
            ->assertSessionHasErrors('justificatif');

        $this->actingAs($agent)
            ->post(route('workspace.actions.sub-actions.update', [$action, $sousAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'commentaire' => 'Note preparee.',
                'justificatif' => UploadedFile::fake()->create('note-cadrage.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $sousAction->refresh();
        $action->refresh();

        $this->assertTrue((bool) $sousAction->est_effectuee);
        $this->assertSame('en_attente_validation_chef', (string) $sousAction->statut);
        $this->assertSame('100.00', (string) $sousAction->taux_execution);
        $this->assertSame(100.0, app(\App\Services\ActionPerformanceService::class)->calculateDeclaredProgress($action->fresh('sousActions')));
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, (string) $action->statut_validation);
    }

    public function test_quantified_sub_action_requires_realized_quantity(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
        ])->save();
        $action->weeks()->delete();

        $sousAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Traiter les dossiers',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'cible_prevue' => 10,
            'unite' => 'dossiers',
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $this->actingAs($agent)
            ->from(route('workspace.actions.suivi', $action))
            ->post(route('workspace.actions.sub-actions.update', [$action, $sousAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'commentaire' => 'Traitement commence.',
                'difficultes' => 'Aucune difficulte rencontree.',
                'justificatif' => UploadedFile::fake()->create('preuve.pdf', 64, 'application/pdf'),
            ])
            ->assertSessionHasErrors('quantite_realisee');

        $sousAction->refresh();
        $this->assertFalse((bool) $sousAction->est_effectuee);
    }

    public function test_non_quantified_action_submits_with_proof_and_declared_progress_at_100(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SANS_QUANTITE,
            'type_cible' => 'qualitative',
            'quantite_cible' => null,
            'unite_cible' => null,
            'quantite_realisee' => 0,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
        ])->save();
        $action->weeks()->delete();
        $action->sousActions()->delete();

        $this->actingAs($agent)
            ->post(route('workspace.actions.execution.update', $action), [
                'commentaire_quantitatif' => 'Execution terminee avec justificatif.',
                'justificatif_quantitatif' => UploadedFile::fake()->create('preuve-action-non-quantifiable.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();

        $this->assertSame(100.0, app(\App\Services\ActionPerformanceService::class)->calculateDeclaredProgress($action));
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, (string) $action->statut_validation);
        $this->assertDatabaseHas('justificatifs', [
            'justifiable_id' => $action->id,
            'categorie' => 'execution_non_quantitative',
        ]);
    }

    public function test_quantified_action_requires_realized_quantity(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_QUANTITATIF,
            'type_cible' => 'quantitative',
            'quantite_cible' => 100,
            'unite_cible' => 'dossiers',
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
        ])->save();
        $action->weeks()->delete();
        $action->sousActions()->delete();

        $this->actingAs($agent)
            ->from(route('workspace.actions.suivi', $action))
            ->post(route('workspace.actions.execution.update', $action), [
                'commentaire_quantitatif' => 'Progression renseignee.',
                'justificatif_quantitatif' => UploadedFile::fake()->create('preuve-action-quantitative.pdf', 64, 'application/pdf'),
            ])
            ->assertSessionHasErrors('quantite_realisee');

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, (string) $action->statut_validation);
    }

    public function test_agent_updates_planned_sub_action_then_submit_for_chef_review(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
        ])->save();
        $action->weeks()->delete();

        $sousAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Collecter les pieces de preuve',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertDontSee('Ajouter une sous-action')
            ->assertDontSee('action-submit-closure', false)
            ->assertDontSee('rapport_final', false)
            ->assertDontSee('date_fin_reelle', false)
            ->assertDontSee('Marquer la sous-action comme effectuée')
            ->assertSee('tracking_action', false)
            ->assertSee('Soumettre la sous-action')
            ->assertSee('tracking-entry-form', false)
            ->assertSee('execution_only', false);

        $this->assertSame(1, $action->sousActions()->count());

        $this->actingAs($agent)
            ->post(route('workspace.actions.sub-actions.update', [$action, $sousAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'commentaire' => 'Sous-action realisee',
                'difficultes' => 'Aucune difficulte rencontree.',
                'justificatif' => UploadedFile::fake()->create('preuve-suivi.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, $action->statut_validation);

        $this->actingAs($agent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertDontSee('commentaire_sous_action_'.$sousAction->id, false);

        $this->actingAs($serviceUser)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Controle chef de service')
            ->assertSee('decision_sous_action', false);
    }

    public function test_action_is_not_submitted_to_chef_until_all_planned_sub_actions_are_done(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
        ])->save();
        $action->weeks()->delete();

        $firstSubAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Collecter les pieces',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-07',
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $secondSubAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Verifier les pieces',
            'date_debut' => '2026-01-08',
            'date_fin' => '2026-01-14',
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $this->actingAs($agent)
            ->post(route('workspace.actions.sub-actions.update', [$action, $firstSubAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'commentaire' => 'Premiere sous-action realisee.',
                'difficultes' => 'Aucune difficulte rencontree.',
                'justificatif' => UploadedFile::fake()->create('preuve-premiere.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, (string) $action->statut_validation);

        $this->actingAs($agent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('commentaire_sous_action_'.$secondSubAction->id, false)
            ->assertDontSee('Saisie gel');

        $this->actingAs($agent)
            ->post(route('workspace.actions.sub-actions.update', [$action, $secondSubAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'commentaire' => 'Deuxieme sous-action realisee.',
                'difficultes' => 'Aucune difficulte rencontree.',
                'justificatif' => UploadedFile::fake()->create('preuve-deuxieme.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, (string) $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.sub-actions.review', [$action, $firstSubAction]), [
                'decision_sous_action' => 'valider',
                'commentaire_sous_action' => 'Premiere sous-action conforme.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, (string) $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.sub-actions.review', [$action, $secondSubAction]), [
                'decision_sous_action' => 'valider',
                'commentaire_sous_action' => 'Deuxieme sous-action conforme.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, (string) $action->statut_validation);
    }

    public function test_chef_can_validate_or_reject_pending_sub_action(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
        ])->save();
        $action->weeks()->delete();

        $validatedSubAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Sous-action a valider',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'statut' => 'en_attente_validation_chef',
            'est_effectuee' => true,
            'date_realisation' => now(),
            'completed_at' => now(),
            'taux_execution' => 100,
        ]);

        $this->actingAs($serviceUser)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Controle chef de service')
            ->assertSee('Demander correction');

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.sub-actions.review', [$action, $validatedSubAction]), [
                'decision_sous_action' => 'valider',
                'commentaire_sous_action' => 'Conforme.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $validatedSubAction->refresh();
        $this->assertSame('validee_chef', (string) $validatedSubAction->statut);
        $this->assertTrue((bool) $validatedSubAction->est_effectuee);

        $rejectedSubAction = $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => 'Sous-action a corriger',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'statut' => 'en_attente_validation_chef',
            'est_effectuee' => true,
            'date_realisation' => now(),
            'completed_at' => now(),
            'taux_execution' => 100,
        ]);
        $action->forceFill(['statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF])->save();

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.sub-actions.review', [$action, $rejectedSubAction]), [
                'decision_sous_action' => 'demander_correction',
                'commentaire_sous_action' => 'Piece illisible, merci de corriger.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $rejectedSubAction->refresh();
        $action->refresh();
        $this->assertSame('rejetee_a_corriger', (string) $rejectedSubAction->statut);
        $this->assertFalse((bool) $rejectedSubAction->est_effectuee);
        $this->assertSame(ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE, (string) $action->statut_validation);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'sous_action_correction_demandee',
            'utilisateur_id' => $serviceUser->id,
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('commentaire_sous_action_'.$rejectedSubAction->id, false);
    }

    public function test_assigned_agent_can_submit_own_sub_action_even_when_not_action_responsible(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $otherAgent = $fixture['other_agent'];

        $action->forceFill([
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'type_cible' => 'qualitative',
        ])->save();
        $action->responsables()->sync([
            $agent->id => ['is_primary' => true],
            $otherAgent->id => ['is_primary' => false],
        ]);
        $action->weeks()->delete();

        $sousAction = $action->sousActions()->create([
            'agent_id' => $otherAgent->id,
            'libelle' => 'Traiter le dossier commun',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'cible_prevue' => 1,
            'unite' => 'dossier',
            'quantite_realisee' => 0,
            'taux_realisation' => 0,
            'statut' => 'non_demarre',
            'est_effectuee' => false,
            'taux_execution' => 0,
        ]);

        $this->actingAs($otherAgent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Traiter le dossier commun')
            ->assertDontSee('RMO diff')
            ->assertSee('tracking-entry-form', false)
            ->assertSee('quantite_realisee_sous_action_'.$sousAction->id, false);

        $this->actingAs($otherAgent)
            ->post(route('workspace.actions.sub-actions.update', [$action, $sousAction]), [
                '_method' => 'PUT',
                'execution_only' => '1',
                'est_effectuee' => '1',
                'quantite_realisee' => 1,
                'commentaire' => 'Sous-action realisee',
                'difficultes' => 'Aucune difficulte rencontree.',
                'justificatif' => UploadedFile::fake()->create('preuve-second-agent.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $sousAction->refresh();
        $action->refresh();
        $this->assertTrue((bool) $sousAction->est_effectuee);
        $this->assertSame('en_attente_validation_chef', (string) $sousAction->statut);
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, (string) $action->statut_validation);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'sous_action_effectuee',
            'utilisateur_id' => $otherAgent->id,
        ]);
    }

    public function test_action_validation_workflow_finishes_at_chef_and_direction_can_read(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $directionUser = $fixture['direction_user'];

        $this->completeAllWeeksAsResponsable($action, $agent);

        $action->refresh();
        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Travail conforme',
                'validation_sans_correction' => 1,
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
        $this->assertTrue((bool) $action->validation_hierarchique);

        $this->actingAs($directionUser)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Action securite test');

        $this->actingAs($directionUser)
            ->post(route('workspace.actions.review-direction', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Validation finale',
            ])
            ->assertForbidden();

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
        $this->assertDatabaseHas('action_kpis', [
            'action_id' => $action->id,
        ]);
    }

    public function test_chef_can_request_correction_and_agent_can_resume_execution(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];

        $this->completeAllWeeksAsResponsable($action, $agent);

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'demander_correction',
                'motif_validation_chef' => 'Merci de completer les preuves.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();

        $this->assertSame(ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_A_CORRIGER, $action->statut_dynamique);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'action_correction_demandee',
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Correction demandee');
    }

    public function test_delegated_service_user_can_review_submitted_action(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $delegateServiceUser = $fixture['delegate_service_user'];

        Delegation::query()->create([
            'delegant_id' => $serviceUser->id,
            'delegue_id' => $delegateServiceUser->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $serviceUser->direction_id,
            'service_id' => $serviceUser->service_id,
            'permissions' => ['action_review'],
            'motif' => 'Absence temporaire',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDays(7),
            'statut' => 'active',
        ]);

        $this->completeAllWeeksAsResponsable($action, $agent);

        $this->actingAs($delegateServiceUser)
            ->get(route('workspace.actions.index'))
            ->assertOk()
            ->assertSee('Action securite test');

        $this->actingAs($delegateServiceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Validation par delegation',
                'validation_sans_correction' => 1,
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
    }

    public function test_responsable_cannot_validate_own_action(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $serviceUser = $fixture['service_user'];
        $directionUser = $fixture['direction_user'];

        $action->forceFill([
            'responsable_id' => $serviceUser->id,
            'contexte_action' => Action::CONTEXT_OPERATIONNEL,
            'origine_action' => Action::ORIGIN_INTERNE,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
        ])->save();

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Auto validation interdite',
                'validation_sans_correction' => 1,
            ])
            ->assertForbidden();

        $action->forceFill([
            'responsable_id' => $directionUser->id,
            'contexte_action' => Action::CONTEXT_OPERATIONNEL,
            'origine_action' => Action::ORIGIN_INTERNE,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ])->save();

        $this->actingAs($directionUser)
            ->post(route('workspace.actions.review-direction', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Auto validation direction interdite',
            ])
            ->assertForbidden();
    }

    public function test_non_agent_responsable_can_execute_operational_action(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $directionUser = $fixture['direction_user'];

        $action->forceFill([
            'responsable_id' => $directionUser->id,
            'contexte_action' => Action::CONTEXT_OPERATIONNEL,
            'origine_action' => Action::ORIGIN_INTERNE,
        ])->save();

        $this->completeAllWeeksAsResponsable($action->fresh(), $directionUser);

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);
        $this->assertSame($directionUser->id, (int) $action->soumise_par);
    }

    public function test_comment_thread_is_persisted_on_action(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $serviceUser = $fixture['service_user'];

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.comment', $action), [
                'message' => 'Merci de preciser le point de blocage.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'commentaire',
            'message' => 'Merci de preciser le point de blocage.',
            'utilisateur_id' => $serviceUser->id,
        ]);
    }

    public function test_workflow_can_finalize_at_service_level_when_direction_step_is_disabled(): void
    {
        Storage::fake('local');

        app(WorkflowSettings::class)->updateActionWorkflow([
            'actions_service_validation_enabled' => '1',
            'actions_direction_validation_enabled' => '0',
            'actions_rejection_comment_required' => '1',
        ]);

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $directionUser = $fixture['direction_user'];

        $this->completeAllWeeksAsResponsable($action, $agent);

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Validation finale service',
                'validation_sans_correction' => 1,
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
        self::assertTrue((bool) $action->validation_hierarchique);

        $this->actingAs($directionUser)
            ->post(route('workspace.actions.review-direction', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Tentative hors circuit',
            ])
            ->assertForbidden();
    }

    public function test_workflow_keeps_chef_as_final_stage_even_when_direction_setting_is_requested(): void
    {
        Mail::fake();
        Storage::fake('local');

        app(WorkflowSettings::class)->updateActionWorkflow([
            'actions_service_validation_enabled' => '0',
            'actions_direction_validation_enabled' => '1',
            'actions_rejection_comment_required' => '1',
        ]);

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $directionUser = $fixture['direction_user'];

        $this->completeAllWeeksAsResponsable($action, $agent);

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);
        self::assertFalse(app(WorkflowSettings::class)->directionValidationEnabled());

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Tentative hors circuit',
                'validation_sans_correction' => 1,
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);

        $this->actingAs($directionUser)
            ->post(route('workspace.actions.review-direction', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Validation direction',
            ])
            ->assertForbidden();

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
    }

    /**
     * Soumet toutes les périodes hebdomadaires d'une action avec une quantité
     * réalisée de 100 sur la première période, déclenchant ainsi la bascule
     * automatique vers le chef de service en fin de planning.
     */
    private function completeAllWeeksAsResponsable(Action $action, User $responsable): void
    {
        $this->actingAs($responsable)
            ->post(route('workspace.actions.execution.update', $action), [
                'quantite_realisee' => 100,
                'commentaire_quantitatif' => 'Progression quantitative renseignee.',
                'difficultes_quantitatives' => 'Aucune difficulte rencontree.',
                'justificatif_quantitatif' => UploadedFile::fake()->create('preuve-quantitative.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));
    }

    /**
     * @return array{action: Action, agent: User, other_agent: User, service_user: User, direction_user: User, delegate_service_user: User}
     */
    private function createActionFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-SEC',
            'libelle' => 'Direction Securite',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-SEC',
            'libelle' => 'Service Securite',
            'actif' => true,
        ]);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-SEC-01',
            'agent_fonction' => 'Agent execution',
            'password_changed_at' => now(),
        ]);

        $otherAgent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-SEC-02',
            'agent_fonction' => 'Autre agent',
            'password_changed_at' => now(),
        ]);

        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $directionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $delegateServiceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS securite',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-SEC',
            'libelle' => 'Axe securite',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-SEC-1',
            'libelle' => 'Objectif securite',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO securite',
            'statut' => 'brouillon',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA securite',
            'statut' => 'brouillon',
        ]);

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action securite test',
            'description' => 'Action de test securite',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'date_echeance' => '2026-01-14',
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);

        app(ActionTrackingService::class)->initializeActionTracking($action, $serviceUser);

        return [
            'action' => $action->fresh(),
            'agent' => $agent,
            'other_agent' => $otherAgent,
            'service_user' => $serviceUser,
            'direction_user' => $directionUser,
            'delegate_service_user' => $delegateServiceUser,
        ];
    }
}
