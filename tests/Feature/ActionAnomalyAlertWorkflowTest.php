<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ActionAnomalyAlertWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_profile_can_signal_anomaly_that_notifies_and_creates_personal_task(): void
    {
        Notification::fake();

        $fixture = $this->fixture();
        $planification = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($planification)
            ->post(route('workspace.actions.anomalies.signal', $fixture['action']), [
                'type_anomalie' => 'justificatif_manquant',
                'niveau' => 'warning',
                'cible_role' => 'responsable',
                'message' => 'Impossible de valider : justificatif manquant.',
                'correction_attendue' => 'Televerser la piece justificative.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $log = ActionLog::query()->where('action_id', $fixture['action']->id)->firstOrFail();

        $this->assertSame('anomalie_justificatif_manquant', $log->type_evenement);
        $this->assertSame('validation', $log->details['blocked_scope'] ?? null);
        $this->assertFalse((bool) ($log->details['resolved'] ?? true));

        Notification::assertSentTo(
            $fixture['agent'],
            WorkspaceModuleNotification::class,
            fn (WorkspaceModuleNotification $notification): bool => ($notification->toArray($fixture['agent'])['module'] ?? '') === 'alertes'
        );

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Correction anomalie')
            ->assertSee('Impossible de valider');
    }

    public function test_resolved_anomaly_leaves_personal_tasks(): void
    {
        $fixture = $this->fixture();
        $planification = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'password_changed_at' => now(),
        ]);
        $log = ActionLog::query()->create([
            'action_id' => $fixture['action']->id,
            'niveau' => 'warning',
            'type_evenement' => 'anomalie_commentaire_absent',
            'message' => 'Commentaire de suivi absent.',
            'details' => [
                'manual' => true,
                'resolved' => false,
                'blocked_scope' => 'soumission',
            ],
            'cible_role' => 'responsable',
            'utilisateur_id' => $planification->id,
            'lu' => false,
        ]);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Commentaire de suivi absent');

        $this->actingAs($planification)
            ->post(route('workspace.actions.anomalies.resolve', [$fixture['action'], $log]), [
                'commentaire_resolution' => 'Correction confirmee.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $this->assertTrue((bool) (($log->fresh()->details)['resolved'] ?? false));

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertDontSee('Commentaire de suivi absent');
    }

    public function test_manual_action_submission_route_is_removed(): void
    {
        $this->assertFalse(Route::has('workspace.actions.submit-closure'));
    }

    public function test_active_validation_anomaly_blocks_chef_validation_until_resolved(): void
    {
        $fixture = $this->fixture();
        $action = $fixture['action'];

        $action->forceFill([
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'soumise_par' => $fixture['agent']->id,
            'soumise_le' => now(),
        ])->save();

        $log = ActionLog::query()->create([
            'action_id' => $action->id,
            'niveau' => 'warning',
            'type_evenement' => 'anomalie_justificatif_manquant',
            'message' => 'Impossible de valider : justificatif manquant.',
            'details' => [
                'manual' => true,
                'resolved' => false,
                'blocked_scope' => 'validation',
                'correction_attendue' => 'Televerser la piece justificative.',
            ],
            'cible_role' => 'chef_service',
            'utilisateur_id' => null,
            'lu' => false,
        ]);

        $this->actingAs($fixture['chef'])
            ->from(route('workspace.actions.suivi', $action))
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Conforme.',
            ])
            ->assertSessionHasErrors('general');

        $this->assertSame(
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            (string) $action->fresh()->statut_validation
        );

        $details = $log->details;
        $details['resolved'] = true;
        $log->forceFill(['details' => $details])->save();

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'motif_validation_chef' => 'Conforme.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            (string) $action->fresh()->statut_validation
        );
    }

    public function test_active_financing_anomaly_blocks_daf_review(): void
    {
        $fixture = $this->fixture();
        $action = $fixture['action'];
        $daf = Direction::query()->create([
            'code' => 'DAF',
            'libelle' => 'Direction Administrative et Financiere',
            'actif' => true,
        ]);
        $dafDirector = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $daf->id,
            'password_changed_at' => now(),
        ]);

        $action->forceFill([
            'financement_requis' => true,
            'description_financement' => 'Besoin budgetaire a traiter.',
            'source_financement' => 'Budget interne',
            'montant_estime' => 500000,
            'financement_statut' => Action::FINANCEMENT_SOUMIS_DAF,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ])->save();

        ActionLog::query()->create([
            'action_id' => $action->id,
            'niveau' => 'warning',
            'type_evenement' => 'anomalie_financement_incomplet',
            'message' => 'Financement incomplet.',
            'details' => [
                'manual' => true,
                'resolved' => false,
                'blocked_scope' => 'circuit_daf',
                'correction_attendue' => 'Completer la piece de financement.',
            ],
            'cible_role' => 'daf',
            'utilisateur_id' => null,
            'lu' => false,
        ]);

        $this->actingAs($dafDirector)
            ->from(route('workspace.actions.suivi', $action))
            ->post(route('workspace.actions.financement.daf', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_VALIDER,
                'montant_valide' => 500000,
                'reference_financement' => 'DAF-BLOCK-001',
                'commentaire_financement' => 'Dossier recevable.',
            ])
            ->assertSessionHasErrors('general');

        $this->assertSame(
            Action::FINANCEMENT_SOUMIS_DAF,
            (string) $action->fresh()->financement_statut
        );
        $this->assertNull($action->fresh()->financement_daf_par);
    }

    /**
     * @return array{action: Action, agent: User, chef: User}
     */
    private function fixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-ALERT',
            'libelle' => 'Direction alerte',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-ALERT',
            'libelle' => 'Service alerte',
            'actif' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $chef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS alerte',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-ALERT',
            'libelle' => 'Axe alerte',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-ALERT',
            'libelle' => 'Objectif alerte',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO alerte',
            'statut' => 'valide',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA alerte',
            'statut' => 'en_cours',
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'libelle' => 'Action controle alerte',
            'description' => 'Action test anomalie',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'date_echeance' => now()->addWeek()->toDateString(),
            'responsable_id' => $agent->id,
            'financement_requis' => false,
        ]);
        $action->forceFill([
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
        ])->save();

        return compact('action', 'agent', 'chef');
    }
}
