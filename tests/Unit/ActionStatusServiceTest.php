<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Justificatif;
use App\Models\SousAction;
use App\Services\Actions\ActionStatusService;
use App\Services\Actions\ActionTrackingService;
use Tests\TestCase;

class ActionStatusServiceTest extends TestCase
{
    public function test_action_submitted_to_chef_is_started_not_non_started(): void
    {
        $action = new Action([
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'progression_reelle' => 0,
        ]);

        $service = app(ActionStatusService::class);

        $this->assertTrue($service->isStarted($action));
        $this->assertFalse($service->isNotStarted($action));
        $this->assertSame('en_cours', $service->dashboardStatus($action));
    }

    public function test_action_with_only_created_sub_action_is_not_started(): void
    {
        $action = new Action([
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
        ]);
        $action->setRelation('sousActions', collect([new SousAction(['libelle' => 'Sous-action creee'])]));

        $service = app(ActionStatusService::class);

        $this->assertFalse($service->isStarted($action));
        $this->assertTrue($service->isNotStarted($action));
        $this->assertSame('non_demarre', $service->dashboardStatus($action));
    }

    public function test_action_with_started_or_submitted_sub_action_is_started(): void
    {
        $service = app(ActionStatusService::class);

        foreach (['en_cours', 'en_attente_validation_chef'] as $status) {
            $action = new Action([
                'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
                'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
                'progression_reelle' => 0,
            ]);
            $action->setRelation('sousActions', collect([new SousAction([
                'libelle' => 'Sous-action engagee',
                'statut' => $status,
            ])]));

            $this->assertTrue($service->isStarted($action), "Le statut {$status} doit demarrer l'action.");
            $this->assertFalse($service->isNotStarted($action), "Le statut {$status} ne doit pas rester non demarre.");
            $this->assertSame('en_cours', $service->dashboardStatus($action));
        }
    }

    public function test_financing_proof_and_generic_comment_do_not_start_action(): void
    {
        $action = new Action([
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
        ]);
        $action->setRelation('justificatifs', collect([
            new Justificatif(['categorie' => 'financement']),
        ]));
        $action->setRelation('actionLogs', collect([
            new ActionLog(['type_evenement' => 'commentaire']),
        ]));

        $service = app(ActionStatusService::class);

        $this->assertFalse($service->isStarted($action));
        $this->assertSame('non_demarre', $service->dashboardStatus($action));
    }

    public function test_execution_proof_or_execution_log_starts_action(): void
    {
        $service = app(ActionStatusService::class);

        $actionWithProof = new Action([
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
        ]);
        $actionWithProof->setRelation('justificatifs', collect([
            new Justificatif(['categorie' => 'execution_quantitative']),
        ]));

        $actionWithExecutionLog = new Action([
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
        ]);
        $actionWithExecutionLog->setRelation('actionLogs', collect([
            new ActionLog(['type_evenement' => 'execution_quantitative']),
        ]));

        $this->assertTrue($service->isStarted($actionWithProof));
        $this->assertTrue($service->isStarted($actionWithExecutionLog));
    }

    public function test_default_official_validation_accepts_chef_level_and_keeps_direction_history(): void
    {
        $service = app(ActionStatusService::class);

        $chefValidated = new Action([
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);
        $directionValidated = new Action([
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ]);

        $this->assertTrue($service->isOfficiallyValidated($chefValidated));
        $this->assertTrue($service->isOfficiallyValidated($directionValidated));
    }

    public function test_imported_action_not_yet_registered_is_a_parametrer(): void
    {
        // Action importee via Excel : statut_parametrage='a_parametrer' tant que le
        // chef de service ne l'a pas enregistree officiellement dans le PTA.
        $action = new Action([
            'statut_parametrage' => 'a_parametrer',
            'statut' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
        ]);

        $service = app(ActionStatusService::class);

        $this->assertTrue($service->isPendingSetup($action));
        $this->assertSame('a_parametrer', $service->dashboardStatus($action));
    }

    public function test_registered_action_without_tracking_is_non_demarre(): void
    {
        // Action enregistree dans le PTA (statut_parametrage='parametre') mais aucun
        // suivi d'execution n'a encore commence => non demarree.
        $action = new Action([
            'statut_parametrage' => 'parametre',
            'statut' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
        ]);

        $service = app(ActionStatusService::class);

        $this->assertFalse($service->isPendingSetup($action));
        $this->assertTrue($service->isNotStarted($action));
        $this->assertSame('non_demarre', $service->dashboardStatus($action));
    }

    public function test_registered_action_with_progress_is_en_cours(): void
    {
        $action = new Action([
            'statut_parametrage' => 'parametre',
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 40,
        ]);

        $service = app(ActionStatusService::class);

        $this->assertTrue($service->isStarted($action));
        $this->assertSame('en_cours', $service->dashboardStatus($action));
    }

    public function test_chef_validated_action_is_acheve(): void
    {
        // Validation chef de service = etape terminale ANBG => achevee, meme si le
        // statut dynamique n'a pas (encore) bascule sur un statut acheve.
        $action = new Action([
            'statut_parametrage' => 'parametre',
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            'progression_reelle' => 80,
        ]);

        $service = app(ActionStatusService::class);

        $this->assertTrue($service->isCompleted($action));
        $this->assertSame('acheve', $service->dashboardStatus($action));
    }

    public function test_submitted_action_at_full_progress_stays_pending_validation(): void
    {
        $action = new Action([
            'statut_parametrage' => 'parametre',
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'progression_reelle' => 100,
            'date_fin_reelle' => now()->toDateString(),
        ]);

        $service = app(ActionStatusService::class);

        $this->assertFalse($service->isCompleted($action));
        $this->assertSame('en_cours', $service->dashboardStatus($action));
    }
}
