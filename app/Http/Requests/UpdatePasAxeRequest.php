<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePasAxeRequest extends FormRequest
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
        $codeRule = Rule::unique('pas_axes', 'code')
            ->where(fn ($query) => $query->where('pas_id', $this->input('pas_id')));

        $currentId = $this->resolveCurrentPasAxeId();
        if ($currentId !== null) {
            $codeRule = $codeRule->ignore($currentId);
        }

        return [
            'pas_id' => ['required', 'integer', 'exists:pas,id'],
            'code' => ['required', 'string', 'max:30', $codeRule],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'ordre' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Le code d axe est deja utilise pour ce PAS.',
        ];
    }

    private function resolveCurrentPasAxeId(): ?int
    {
        $routeValue = $this->route('pasAxe') ?? $this->input('id');

        if (is_object($routeValue) && method_exists($routeValue, 'getKey')) {
            return (int) $routeValue->getKey();
        }

        if (is_numeric($routeValue)) {
            return (int) $routeValue;
        }

        return null;
    }
}

