<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeletePtaSuiviActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ! $user->isAgent();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'row_type' => ['required', Rule::in(['action', 'sous_action'])],
            'sous_action_id' => [
                Rule::requiredIf(fn (): bool => (string) $this->input('row_type') === 'sous_action'),
                'nullable',
                'integer',
                'exists:sous_actions,id',
            ],
        ];
    }
}
