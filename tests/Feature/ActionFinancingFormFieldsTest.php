<?php

namespace Tests\Feature;

use Tests\TestCase;

class ActionFinancingFormFieldsTest extends TestCase
{
    public function test_financing_nature_field_is_visible_in_action_and_pta_forms(): void
    {
        $actionForm = (string) file_get_contents(resource_path('views/workspace/actions/form.blade.php'));
        $ptaForm = (string) file_get_contents(resource_path('views/workspace/pta/form.blade.php'));
        $ptaActionBlock = (string) file_get_contents(resource_path('views/workspace/pta/partials/action-form-block.blade.php'));

        $this->assertStringContainsString('name="nature_financement"', $actionForm);
        $this->assertStringContainsString('Nature du financement', $actionForm);
        $this->assertStringContainsString('name="actions[{{ $index }}][nature_financement]"', $ptaActionBlock);
        $this->assertStringContainsString('Nature du financement', $ptaActionBlock);
        $this->assertStringContainsString("field.name.indexOf('[nature_financement]')", $ptaForm);
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
