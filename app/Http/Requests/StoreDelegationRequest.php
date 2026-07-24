<?php

namespace App\Http\Requests;

use App\Models\Delegation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasPermission('delegations.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'delegant_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            'delegue_id' => [
                'required',
                'integer',
                'different:delegant_id',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            'role_scope' => ['required', Rule::in([Delegation::SCOPE_DIRECTION, Delegation::SCOPE_SERVICE])],
            'direction_id' => [
                'required',
                'integer',
                Rule::exists('directions', 'id')->where('actif', true),
            ],
            'service_id' => [
                'nullable',
                'integer',
                'required_if:role_scope,'.Delegation::SCOPE_SERVICE,
                Rule::exists('services', 'id')->where('actif', true),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(Delegation::AVAILABLE_PERMISSIONS),
            ],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after:date_debut'],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
