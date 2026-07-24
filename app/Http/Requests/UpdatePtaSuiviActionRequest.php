<?php

namespace App\Http\Requests;

use App\Enums\TypeIndicateur;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePtaSuiviActionRequest extends FormRequest
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
            'sous_action_id' => ['nullable', 'required_if:row_type,sous_action', 'integer', 'exists:sous_actions,id'],
            'libelle' => ['required', 'string', 'max:255'],
            'type_indicateur' => ['required', Rule::in(TypeIndicateur::values())],
            'indicateur' => ['nullable', 'string', 'max:1000'],
            'livrable_attendu' => ['nullable', 'string', 'max:1000'],
            'quantite_a_realiser' => ['nullable', 'numeric', 'min:0'],
            'seuil_minimum' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unite' => ['nullable', 'string', 'max:100'],
            'rmo_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_debut' => ['prohibited'],
            'date_fin' => ['prohibited'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'libelle' => 'libellé',
            'type_indicateur' => 'type d’indicateur',
            'indicateur' => 'indicateur de mesure',
            'livrable_attendu' => 'livrable attendu',
            'quantite_a_realiser' => 'quantité cible',
            'seuil_minimum' => 'seuil',
            'unite' => 'unité',
            'rmo_id' => 'RMO',
            'date_debut' => 'date de début',
            'date_fin' => 'date de fin',
        ];
    }
}
