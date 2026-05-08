<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPaoObjectifStrategique;
use App\Models\PaoAxe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaoObjectifStrategiqueRequest extends FormRequest
{
    use ValidatesPaoObjectifStrategique;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->paoObjectifStrategiqueRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->paoObjectifStrategiqueMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $echeance = $this->input('echeance');
            if ($echeance === null) {
                return;
            }
            $paoAxe = PaoAxe::query()->with('pao:id,echeance')->find((int) $this->input('pao_axe_id'));
            $paoEcheance = $paoAxe?->pao?->echeance;
            if ($paoEcheance && $echeance > (string) $paoEcheance) {
                $validator->errors()->add(
                    'echeance',
                    'L échéance de l objectif stratégique ne peut pas dépasser celle du PAO parent ('.$paoEcheance.').'
                );
            }
        });
    }
}
