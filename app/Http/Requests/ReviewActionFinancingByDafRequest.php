<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewActionFinancingByDafRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $action = $this->route('action');

        return $user instanceof User
            && $action instanceof Action
            && $user->can('reviewFinancingByDaf', $action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $documentPolicy = app(DocumentPolicySettings::class);

        return [
            'decision_financement' => ['required', Rule::in([
                ActionTrackingService::FINANCEMENT_DECISION_VALIDER,
                ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT,
                ActionTrackingService::FINANCEMENT_DECISION_REJETER,
            ])],
            'montant_valide' => [
                'required_if:decision_financement,'.ActionTrackingService::FINANCEMENT_DECISION_VALIDER,
                'nullable',
                'numeric',
                'min:0.01',
                'max:9999999999999.99',
            ],
            'reference_financement' => [
                'required_if:decision_financement,'.ActionTrackingService::FINANCEMENT_DECISION_VALIDER,
                'nullable',
                'string',
                'max:255',
            ],
            'commentaire_financement' => ['required', 'string', 'min:5', 'max:3000'],
            'justificatif_financement_daf' => [
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
            'reference_financement' => trim((string) $this->input('reference_financement')),
            'commentaire_financement' => trim((string) $this->input('commentaire_financement')),
        ]);
    }
}
