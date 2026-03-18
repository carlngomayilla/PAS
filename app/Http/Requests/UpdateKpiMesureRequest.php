<?php

namespace App\Http\Requests;

use App\Models\Kpi;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateKpiMesureRequest extends FormRequest
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
        $uniqueMesureRule = Rule::unique('kpi_mesures')
            ->where(fn ($query) => $query
                ->where('kpi_id', $this->input('kpi_id'))
                ->where('periode', $this->input('periode'))
            );

        $currentId = $this->resolveCurrentKpiMesureId();
        if ($currentId !== null) {
            $uniqueMesureRule = $uniqueMesureRule->ignore($currentId);
        }

        return [
            'kpi_id' => ['required', 'integer', 'exists:kpis,id'],
            'periode' => ['required', 'string', 'max:20', $uniqueMesureRule],
            'valeur' => ['required', 'numeric', 'min:0'],
            'commentaire' => ['nullable', 'string'],
            'saisi_par' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periode.unique' => 'Une mesure existe deja pour cette periode sur ce KPI.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $saisiPar = $this->input('saisi_par');
            if ($saisiPar === null) {
                return;
            }

            $kpi = Kpi::query()
                ->with('action.pta:id,direction_id')
                ->find((int) $this->input('kpi_id'));

            $user = User::query()->find((int) $saisiPar);

            if ($kpi === null || $user === null || $user->direction_id === null) {
                return;
            }

            $directionId = (int) $kpi->action?->pta?->direction_id;
            if ($directionId > 0 && (int) $user->direction_id !== $directionId) {
                $validator->errors()->add(
                    'saisi_par',
                    'L utilisateur de saisie doit appartenir a la meme direction que le KPI.'
                );
            }
        });
    }

    private function resolveCurrentKpiMesureId(): ?int
    {
        $routeValue = $this->route('kpiMesure') ?? $this->input('id');

        if (is_object($routeValue) && method_exists($routeValue, 'getKey')) {
            return (int) $routeValue->getKey();
        }

        if (is_numeric($routeValue)) {
            return (int) $routeValue;
        }

        return null;
    }
}
