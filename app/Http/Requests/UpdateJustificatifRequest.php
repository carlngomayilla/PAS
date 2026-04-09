<?php

namespace App\Http\Requests;

use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJustificatifRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'fichier' => [
                'nullable',
                'file',
                'max:'.$documentPolicy->maxUploadKilobytes(),
                $documentPolicy->mimesRule(),
            ],
        ];
    }
}
