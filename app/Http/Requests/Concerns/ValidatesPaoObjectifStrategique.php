<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesPaoObjectifStrategique
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function paoObjectifStrategiqueRules(?int $ignoreId = null): array
    {
        $codeRule = Rule::unique('pao_objectifs_strategiques', 'code')
            ->where(function ($query) {
                return $query->where('pao_axe_id', $this->input('pao_axe_id'));
            });

        if ($ignoreId !== null) {
            $codeRule = $codeRule->ignore($ignoreId);
        }

        return [
            'pao_axe_id' => ['required', 'integer', 'exists:pao_axes,id'],
            'code' => ['required', 'string', 'max:30', $codeRule],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'echeance' => ['nullable', 'date', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paoObjectifStrategiqueMessages(): array
    {
        return [
            'code.unique' => 'Le code est deja utilise pour cet axe du PAO.',
        ];
    }

    protected function resolveCurrentPaoObjectifStrategiqueId(): ?int
    {
        $routeValue = $this->route('paoObjectifStrategique')
            ?? $this->route('pao_objectif_strategique')
            ?? $this->input('id');

        if (is_object($routeValue) && method_exists($routeValue, 'getKey')) {
            return (int) $routeValue->getKey();
        }

        if (is_numeric($routeValue)) {
            return (int) $routeValue;
        }

        return null;
    }
}

