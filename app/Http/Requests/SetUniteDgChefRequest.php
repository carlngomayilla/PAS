<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetUniteDgChefRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_ADMIN_FONCTIONNEL);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'chef_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'chef_user_id.exists' => 'Le chef sélectionné est introuvable ou a été supprimé.',
        ];
    }
}
