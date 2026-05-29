<?php

namespace Tests\Unit;

use App\Support\UiLabel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UiLabelTest extends TestCase
{
    #[Test]
    public function it_maps_action_and_validation_statuses_to_french_labels(): void
    {
        self::assertSame('En cours', UiLabel::actionStatus('en_cours'));
        self::assertSame('Achevé hors délai', UiLabel::actionStatus('acheve_hors_delai'));
        self::assertSame('Validée', UiLabel::validationStatus('validee_chef'));
        self::assertSame('Validée (ancienne direction)', UiLabel::validationStatus('validee_direction'));
    }

    #[Test]
    public function it_maps_workflow_and_delegation_statuses_to_secondary_screen_labels(): void
    {
        self::assertSame('Brouillon', UiLabel::workflowStatus('brouillon'));
        self::assertSame('Ancien statut validé ou verrouillé', UiLabel::workflowStatus('valide_ou_verrouille'));
        self::assertSame('Active', UiLabel::delegationStatus('active'));
        self::assertSame('Expirée', UiLabel::delegationStatus('expired'));
    }
}
