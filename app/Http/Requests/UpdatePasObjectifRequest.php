<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePasObjectifRequest extends FormRequest
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
        $codeRule = Rule::unique('pas_objectifs', 'code')
            ->where(fn ($query) => $query->where('pas_axe_id', $this->input('pas_axe_id')));

        $currentId = $this->resolveCurrentPasObjectifId();
        if ($currentId !== null) {
            $codeRule = $codeRule->ignore($currentId);
        }

        return [
            'pas_axe_id' => ['required', 'integer', 'exists:pas_axes,id'],
            'code' => ['required', 'string', 'max:30', $codeRule],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'indicateur_global' => ['nullable', 'string', 'max:255'],
            'valeur_cible' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Le code d objectif est deja utilise pour cet axe.',
        ];
    }

    private function resolveCurrentPasObjectifId(): ?int
    {
        $routeValue = $this->route('pasObjectif') ?? $this->input('id');

        if (is_object($routeValue) && method_exists($routeValue, 'getKey')) {
            return (int) $routeValue->getKey();
        }

        if (is_numeric($routeValue)) {
            return (int) $routeValue;
        }

        return null;
    }
}

