<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ManageExportTemplateStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_ADMIN_FONCTIONNEL);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'mark_as_default' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mark_as_default' => $this->boolean('mark_as_default') ? '1' : '0',
        ]);
    }
}
