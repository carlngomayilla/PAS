<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Justificatif;
use App\Models\Pta;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminActionPoliciesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_update_action_policies_and_the_rules_affect_creation_and_closure(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();
        $agent = User::query()->where('email', 'melissa.abogo@anbg.ga')->firstOrFail();
        $pta = Pta::query()->where('service_id', $agent->service_id)->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.action-policies.edit'))
            ->assertOk()
            ->assertSee('Parametres metier des actions');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.action-policies.update'), [
                'actions_risk_plan_required' => '1',
                'actions_manual_suspend_enabled' => '0',
                'actions_auto_complete_when_target_reached' => '1',
                'actions_min_progress_for_closure' => '65',
                'actions_final_justificatif_required' => '1',
            ])
            ->assertRedirect(route('workspace.super-admin.action-policies.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'action_management',
            'key' => 'actions_manual_suspend_enabled',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'action_management_settings_update',
        ]);

        $this->actingAs($admin)
            ->post(route('workspace.actions.store'), [
                'pta_id' => $pta->id,
                'libelle' => 'Action sous politique',
                'description' => 'Test de validation',
                'type_cible' => 'quantitative',
                'unite_cible' => 'dossiers',
                'quantite_cible' => '10',
                'date_debut' => now()->startOfMonth()->format('Y-m-d'),
                'date_fin' => now()->endOfMonth()->format('Y-m-d'),
                'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
                'responsable_id' => $agent->id,
                'statut' => 'suspendu',
                'seuil_alerte_progression' => '10',
                'kpi_periodicite' => 'mensuel',
                'kpi_est_a_renseigner' => '1',
                'financement_requis' => '0',
                'ressource_main_oeuvre' => '1',
            ])
            ->assertSessionHasErrors(['statut', 'risques', 'mesures_preventives']);

        $lowProgressAction = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action a faible progression',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => now()->subDays(10)->toDateString(),
            'date_fin' => now()->addDays(10)->toDateString(),
            'date_echeance' => now()->addDays(10)->toDateString(),
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'progression_reelle' => 40,
            'progression_theorique' => 50,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
        ]);

        Justificatif::query()->create([
            'justifiable_type' => Action::class,
            'justifiable_id' => $lowProgressAction->id,
            'categorie' => 'hebdomadaire',
            'nom_original' => 'suivi.pdf',
            'chemin_stockage' => 'justificatifs/test-suivi.pdf',
            'est_chiffre' => false,
            'mime_type' => 'application/pdf',
            'taille_octets' => 128,
            'description' => 'Justificatif execution',
            'ajoute_par' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->post(route('workspace.actions.close', $lowProgressAction), [
                'date_fin_reelle' => now()->toDateString(),
                'rapport_final' => 'Cloture proposee',
            ])
            ->assertSessionHasErrors(['general']);

        $highProgressAction = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action a forte progression',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => now()->subDays(10)->toDateString(),
            'date_fin' => now()->addDays(10)->toDateString(),
            'date_echeance' => now()->addDays(10)->toDateString(),
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'progression_reelle' => 80,
            'progression_theorique' => 60,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
        ]);

        Justificatif::query()->create([
            'justifiable_type' => Action::class,
            'justifiable_id' => $highProgressAction->id,
            'categorie' => 'hebdomadaire',
            'nom_original' => 'suivi-2.pdf',
            'chemin_stockage' => 'justificatifs/test-suivi-2.pdf',
            'est_chiffre' => false,
            'mime_type' => 'application/pdf',
            'taille_octets' => 128,
            'description' => 'Justificatif execution',
            'ajoute_par' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->post(route('workspace.actions.close', $highProgressAction), [
                'date_fin_reelle' => now()->toDateString(),
                'rapport_final' => 'Cloture proposee',
            ])
            ->assertSessionHasErrors(['justificatif_final']);
    }
}
