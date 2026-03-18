<?php

namespace App\Http\Requests;

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
                'max:10240',
                'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fichier.max' => 'La taille du fichier ne doit pas depasser 10 Mo.',
            'fichier.mimes' => 'Format de fichier non pris en charge.',
        ];
    }
}
