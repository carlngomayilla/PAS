<?php

namespace App\Http\Requests;

use App\Models\Delegation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class CancelDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $this->route('delegation') instanceof Delegation
            && $user->hasPermission('delegations.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'motif_annulation' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
