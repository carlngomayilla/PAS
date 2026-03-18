<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePasRequest extends FormRequest
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
        return [
            'titre' => ['required', 'string', 'max:255'],
            'periode_debut' => ['required', 'integer', 'digits:4', 'min:2000'],
            'periode_fin' => ['required', 'integer', 'digits:4', 'gte:periode_debut'],
            'statut' => ['nullable', Rule::in(['brouillon', 'soumis', 'valide', 'verrouille'])],
            'axes' => ['required', 'array', 'min:1'],
            'axes.*.code' => ['nullable', 'string', 'max:30'],
            'axes.*.libelle' => ['required', 'string', 'max:255'],
            'axes.*.periode_debut' => ['nullable', 'date_format:Y-m-d'],
            'axes.*.periode_fin' => ['nullable', 'date_format:Y-m-d'],
            'axes.*.description' => ['nullable', 'string'],
            'axes.*.ordre' => ['nullable', 'integer', 'min:1'],
            'axes.*.objectifs' => ['required', 'array', 'min:1'],
            'axes.*.objectifs.*.code' => ['nullable', 'string', 'max:30'],
            'axes.*.objectifs.*.libelle' => ['required', 'string', 'max:255'],
            'axes.*.objectifs.*.description' => ['nullable', 'string'],
            'axes.*.objectifs.*.ordre' => ['nullable', 'integer', 'min:1'],
            'axes.*.objectifs.*.indicateur_global' => ['nullable', 'string', 'max:255'],
            'axes.*.objectifs.*.valeur_cible' => ['nullable', 'string', 'max:255'],
            'axes.*.objectifs.*.valeurs_cible' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periode_fin.gte' => 'La periode de fin doit etre superieure ou egale a la periode de debut.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $axes = $this->input('axes', []);
            if (! is_array($axes)) {
                return;
            }

            $codesAxe = [];
            foreach ($axes as $axeIndex => $axe) {
                $axeCode = trim((string) ($axe['code'] ?? ''));
                $axeStart = isset($axe['periode_debut']) ? strtotime((string) $axe['periode_debut']) : false;
                $axeEnd = isset($axe['periode_fin']) ? strtotime((string) $axe['periode_fin']) : false;

                if ($axeStart !== false && $axeEnd !== false && $axeStart > $axeEnd) {
                    $validator->errors()->add(
                        "axes.{$axeIndex}.periode_fin",
                        'La periode de fin de l axe doit etre superieure ou egale a la periode de debut.'
                    );
                }

                if ($axeStart !== false && (int) date('Y', $axeStart) < (int) $this->input('periode_debut')) {
                    $validator->errors()->add(
                        "axes.{$axeIndex}.periode_debut",
                        'La periode de debut de l axe doit rester dans la periode du PAS.'
                    );
                }

                if ($axeEnd !== false && (int) date('Y', $axeEnd) > (int) $this->input('periode_fin')) {
                    $validator->errors()->add(
                        "axes.{$axeIndex}.periode_fin",
                        'La periode de fin de l axe doit rester dans la periode du PAS.'
                    );
                }

                if ($axeCode !== '') {
                    $key = strtolower($axeCode);
                    if (isset($codesAxe[$key])) {
                        $validator->errors()->add(
                            "axes.{$axeIndex}.code",
                            'Le code axe doit etre unique dans un PAS.'
                        );
                    }
                    $codesAxe[$key] = true;
                }

                $objectifs = $axe['objectifs'] ?? [];
                if (! is_array($objectifs)) {
                    continue;
                }

                $codesObjectif = [];
                foreach ($objectifs as $objIndex => $objectif) {
                    $objCode = trim((string) ($objectif['code'] ?? ''));
                    if ($objCode === '') {
                        continue;
                    }

                    $key = strtolower($objCode);
                    if (isset($codesObjectif[$key])) {
                        $validator->errors()->add(
                            "axes.{$axeIndex}.objectifs.{$objIndex}.code",
                            'Le code objectif doit etre unique dans un axe.'
                        );
                    }
                    $codesObjectif[$key] = true;
                }
            }
        });
    }
}
