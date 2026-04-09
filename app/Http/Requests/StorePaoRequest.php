<?php

namespace App\Http\Requests;

use App\Models\PasObjectif;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaoRequest extends FormRequest
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
        $uniqueDirectionRule = Rule::unique('paos')
            ->where(fn ($query) => $query
                ->where('pas_objectif_id', $this->input('pas_objectif_id'))
                ->where('annee', $this->input('annee'))
                ->where('direction_id', $this->input('direction_id'))
            );

        return [
            'pas_objectif_id' => ['required', 'integer', 'exists:pas_objectifs,id'],
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'annee' => ['required', 'integer', 'digits:4', 'min:2000', $uniqueDirectionRule],
            'titre' => ['required', 'string', 'max:255'],
            'echeance' => ['nullable', 'date', 'date_format:Y-m-d'],
            'objectif_operationnel' => ['nullable', 'string'],
            'resultats_attendus' => ['nullable', 'string'],
            'indicateurs_associes' => ['nullable', 'string'],
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
            'annee.unique' => 'Un PAO existe deja pour cette direction, cette annee et cet objectif strategique.',
            'service_id.required' => 'Le service affecte au PAO est obligatoire.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $objectif = PasObjectif::query()
                ->with('pasAxe.pas:id,periode_debut,periode_fin')
                ->find((int) $this->input('pas_objectif_id'));
            $service = Service::query()->find((int) $this->input('service_id'));

            $pas = $objectif?->pasAxe?->pas;
            if ($pas !== null) {
                $annee = (int) $this->input('annee');

                if ($annee < (int) $pas->periode_debut || $annee > (int) $pas->periode_fin) {
                    $validator->errors()->add(
                        'annee',
                        'L annee du PAO doit etre comprise dans la periode du PAS parent.'
                    );
                }
            }

            if ($service !== null && (int) $service->direction_id !== (int) $this->input('direction_id')) {
                $validator->errors()->add(
                    'service_id',
                    'Le service selectionne doit appartenir a la direction du PAO.'
                );
            }

            $echeance = $this->input('echeance');
            if ($echeance !== null) {
                $annee = (int) $this->input('annee');
                $echeanceYear = (int) date('Y', strtotime((string) $echeance));

                if ($echeanceYear !== $annee) {
                    $validator->errors()->add(
                        'echeance',
                        'L echeance doit appartenir a la meme annee que le PAO.'
                    );
                }
            }

            $statut = (string) $this->input('statut', 'brouillon');
            $valideLe = $this->input('valide_le');
            $validePar = $this->input('valide_par');

            if (in_array($statut, ['valide', 'verrouille'], true) && ($valideLe === null || $validePar === null)) {
                $validator->errors()->add(
                    'statut',
                    'Les champs valide_le et valide_par sont obligatoires quand le PAO est valide ou verrouille.'
                );
            }
        });
    }
}
