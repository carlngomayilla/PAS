<?php

namespace Tests\Unit;

use App\Models\Action;
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

    public function test_action_with_created_sub_action_is_started(): void
    {
        $action = new Action([
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
        ]);
        $action->setRelation('sousActions', collect([new SousAction(['libelle' => 'Sous-action creee'])]));

        $this->assertTrue(app(ActionStatusService::class)->isStarted($action));
    }

    public function test_default_official_validation_requires_direction_level(): void
    {
        $service = app(ActionStatusService::class);

        $chefValidated = new Action([
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);
        $directionValidated = new Action([
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ]);

        $this->assertFalse($service->isOfficiallyValidated($chefValidated));
        $this->assertTrue($service->isOfficiallyValidated($directionValidated));
    }
}
