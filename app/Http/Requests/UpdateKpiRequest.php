<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'action_id' => ['required', 'integer', 'exists:actions,id'],
            'libelle' => ['required', 'string', 'max:255'],
            'unite' => ['nullable', 'string', 'max:30'],
            'cible' => ['nullable', 'numeric', 'min:0'],
            'seuil_alerte' => ['nullable', 'numeric', 'min:0'],
            'periodicite' => ['nullable', Rule::in(['mensuel', 'trimestriel', 'semestriel', 'annuel', 'ponctuel'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $cible = $this->input('cible');
            $seuil = $this->input('seuil_alerte');

            if ($cible !== null && $seuil !== null && (float) $seuil > (float) $cible) {
                $validator->errors()->add(
                    'seuil_alerte',
                    'Le seuil d alerte ne doit pas depasser la cible.'
                );
            }
        });
    }
}
