<?php

namespace Tests\Feature;

use Tests\TestCase;

class ActionFinancingFormFieldsTest extends TestCase
{
    public function test_financing_nature_field_is_visible_in_pta_action_block(): void
    {
        // Vue standalone workspace.actions.form.blade.php SUPPRIMEE (2026-05-29) :
        // toutes les actions sont creees/editees via le formulaire PTA. Seul le
        // partial action-form-block.blade.php et la JS du formulaire PTA exposent
        // desormais les champs financement.
        $ptaForm = (string) file_get_contents(resource_path('views/workspace/pta/form.blade.php'));
        $ptaActionBlock = (string) file_get_contents(resource_path('views/workspace/pta/partials/action-form-block.blade.php'));

        $this->assertStringContainsString('name="actions[{{ $index }}][nature_financement]"', $ptaActionBlock);
        $this->assertStringContainsString('Nature du financement', $ptaActionBlock);
        $this->assertStringContainsString("field.name.indexOf('[nature_financement]')", $ptaForm);
    }

    public function test_pta_financing_block_only_exposes_validated_fields(): void
    {
        $ptaActionBlock = (string) file_get_contents(resource_path('views/workspace/pta/partials/action-form-block.blade.php'));

        $this->assertStringContainsString('name="actions[{{ $index }}][financement_requis]"', $ptaActionBlock);
        $this->assertStringContainsString('name="actions[{{ $index }}][montant_estime]"', $ptaActionBlock);
        $this->assertStringContainsString('name="actions[{{ $index }}][nature_financement]"', $ptaActionBlock);
        $this->assertStringContainsString('name="actions[{{ $index }}][justificatif_financement]"', $ptaActionBlock);
        $this->assertStringNotContainsString('name="actions[{{ $index }}][source_financement]"', $ptaActionBlock);
        $this->assertStringNotContainsString('name="actions[{{ $index }}][commentaire_financement]"', $ptaActionBlock);
    }

    public function test_financing_validation_uses_visible_business_labels(): void
    {
        $storeActionRequest = (string) file_get_contents(app_path('Http/Requests/StoreActionRequest.php'));
        $updateActionRequest = (string) file_get_contents(app_path('Http/Requests/UpdateActionRequest.php'));
        $storePtaRequest = (string) file_get_contents(app_path('Http/Requests/StorePtaRequest.php'));
        $updatePtaRequest = (string) file_get_contents(app_path('Http/Requests/UpdatePtaRequest.php'));

        foreach ([$storeActionRequest, $updateActionRequest, $storePtaRequest, $updatePtaRequest] as $requestSource) {
            $this->assertStringContainsString('nature du financement', $requestSource);
            $this->assertStringContainsString('titre de l action', $requestSource);
        }
    }
}
