<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunPlatformSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'actions_service_validation_enabled' => ['required', Rule::in(['1', 1])],
            'actions_direction_validation_enabled' => ['required', Rule::in(['0', 0])],
            'actions_auto_complete_when_target_reached' => ['required', Rule::in(['0', '1', 0, 1])],
            'actions_min_progress_for_closure' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'actions_service_validation_enabled.in' => 'Le visa du chef de service est obligatoire dans le circuit cible.',
            'actions_direction_validation_enabled.in' => 'La validation de direction ne fait plus partie du circuit cible.',
            'actions_min_progress_for_closure.min' => 'Le seuil de clôture doit être compris entre 0 et 100.',
            'actions_min_progress_for_closure.max' => 'Le seuil de clôture doit être compris entre 0 et 100.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'actions_auto_complete_when_target_reached' => $this->boolean('actions_auto_complete_when_target_reached') ? '1' : '0',
        ]);
    }
}
