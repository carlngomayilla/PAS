<?php

namespace App\Http\Requests;

use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJustificatifRequest extends FormRequest
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
        $documentPolicy = app(DocumentPolicySettings::class);

        return [
            'justifiable_type' => [
                'required',
                'string',
                Rule::in([
                    'action',
                    'kpi',
                    'kpi_mesure',
                    \App\Models\Action::class,
                    \App\Models\Kpi::class,
                    \App\Models\KpiMesure::class,
                ]),
            ],
            'justifiable_id' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'fichier' => [
                'required',
                'file',
                'max:'.$documentPolicy->maxUploadKilobytes(),
                $documentPolicy->mimesRule(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fichier.max' => 'La taille du fichier ne doit pas depasser '.app(DocumentPolicySettings::class)->maxUploadMb().' Mo.',
            'fichier.mimes' => 'Format de fichier non pris en charge.',
        ];
    }
}
