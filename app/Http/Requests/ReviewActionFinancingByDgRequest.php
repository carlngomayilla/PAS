<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewActionFinancingByDgRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $action = $this->route('action');

        return $user instanceof User
            && $action instanceof Action
            && $user->can('reviewFinancingByDg', $action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $documentPolicy = app(DocumentPolicySettings::class);

        return [
            'decision_financement' => ['required', Rule::in([
                ActionTrackingService::FINANCEMENT_DECISION_ACCORDER,
                ActionTrackingService::FINANCEMENT_DECISION_REFUSER,
            ])],
            'commentaire_financement' => ['required', 'string', 'min:5', 'max:3000'],
            'justificatif_financement_dg' => [
                'nullable',
                'file',
                'max:'.$documentPolicy->maxUploadKilobytes(),
                $documentPolicy->mimesRule(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'commentaire_financement' => trim((string) $this->input('commentaire_financement')),
        ]);
    }
}
