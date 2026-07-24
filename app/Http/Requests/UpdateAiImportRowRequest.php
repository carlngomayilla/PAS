<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiImportRowRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('ai_pta_import.correct') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', Rule::in(['save', 'reject'])],
            'axe' => ['nullable', 'string'],
            'objectif_strategique' => ['nullable', 'string'],
            'objectif_operationnel' => ['nullable', 'string'],
            'direction' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'libelle_action' => ['nullable', 'string'],
            'sous_action' => ['nullable', 'string'],
            'rmo' => ['nullable', 'string', 'max:255'],
            'cible' => ['nullable', 'string'],
            'type_indicateur' => ['nullable', Rule::in(['quantitatif', 'non_quantitatif', 'mixte'])],
            'quantite_a_realiser' => ['nullable', 'numeric', 'min:0'],
            'livrable_attendu' => ['nullable', 'string'],
            'unite_mesure' => ['nullable', 'string', 'max:100'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'observations' => ['nullable', 'string'],
        ];
    }
}
