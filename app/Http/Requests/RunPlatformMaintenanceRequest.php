<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RunPlatformMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->requiresPasswordConfirmation()
                ? ['required', 'string', 'max:255', 'current_password']
                : ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Confirmez votre mot de passe pour modifier le mode maintenance.',
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
        ];
    }

    private function requiresPasswordConfirmation(): bool
    {
        return in_array((string) $this->route('action'), ['maintenance_on', 'maintenance_off'], true);
    }
}
