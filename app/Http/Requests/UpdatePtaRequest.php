<?php

namespace App\Http\Requests;

use App\Models\Pao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePtaRequest extends FormRequest
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
        $uniquePao = Rule::unique('ptas')
            ->where(fn ($query) => $query->where('pao_id', $this->input('pao_id')));

        $currentId = $this->resolveCurrentPtaId();
        if ($currentId !== null) {
            $uniquePao = $uniquePao->ignore($currentId);
        }

        return [
            'pao_id' => ['required', 'integer', 'exists:paos,id', $uniquePao],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'titre' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(['brouillon', 'soumis', 'valide', 'verrouille'])],
            'valide_le' => ['nullable', 'date'],
            'valide_par' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pao_id.unique' => 'Un PTA existe deja pour ce PAO.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $pao = Pao::query()->find((int) $this->input('pao_id'));
            $directionId = $this->filled('direction_id') ? (int) $this->input('direction_id') : null;
            $serviceId = $this->filled('service_id') ? (int) $this->input('service_id') : null;

            if ($pao !== null && $directionId !== null && (int) $pao->direction_id !== $directionId) {
                $validator->errors()->add(
                    'direction_id',
                    'La direction du PTA doit correspondre a la direction du PAO.'
                );
            }

            if ($pao !== null && $serviceId !== null && (int) $pao->service_id !== $serviceId) {
                $validator->errors()->add(
                    'service_id',
                    'Le service du PTA doit correspondre au service du PAO parent.'
                );
            }

            if ($pao !== null && $pao->service_id === null) {
                $validator->errors()->add(
                    'pao_id',
                    'Le PAO selectionne n est pas encore rattache a un service.'
                );
            }

            $statut = (string) $this->input('statut', 'brouillon');
            $valideLe = $this->input('valide_le');
            $validePar = $this->input('valide_par');

            if (in_array($statut, ['valide', 'verrouille'], true) && ($valideLe === null || $validePar === null)) {
                $validator->errors()->add(
                    'statut',
                    'Les champs valide_le et valide_par sont obligatoires quand le PTA est valide ou verrouille.'
                );
            }
        });
    }

    private function resolveCurrentPtaId(): ?int
    {
        $routeValue = $this->route('pta') ?? $this->input('id');

        if (is_object($routeValue) && method_exists($routeValue, 'getKey')) {
            return (int) $routeValue->getKey();
        }

        if (is_numeric($routeValue)) {
            return (int) $routeValue;
        }

        return null;
    }
}
