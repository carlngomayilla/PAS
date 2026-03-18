<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesPaoAxe
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function paoAxeRules(?int $ignoreId = null): array
    {
        $codeRule = Rule::unique('pao_axes', 'code')
            ->where(function ($query) {
                return $query->where('pao_id', $this->input('pao_id'));
            });

        if ($ignoreId !== null) {
            $codeRule = $codeRule->ignore($ignoreId);
        }

        return [
            'pao_id' => ['required', 'integer', 'exists:paos,id'],
            'code' => ['required', 'string', 'max:30', $codeRule],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'ordre' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paoAxeMessages(): array
    {
        return [
            'code.unique' => 'Le code est deja utilise pour ce PAO.',
        ];
    }

    protected function resolveCurrentPaoAxeId(): ?int
    {
        $routeValue = $this->route('paoAxe')
            ?? $this->route('pao_axe')
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

