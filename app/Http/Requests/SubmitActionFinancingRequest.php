<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitActionFinancingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $action = $this->route('action');

        return $user instanceof User
            && $action instanceof Action
            && $user->can('submitFinancing', $action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $documentPolicy = app(DocumentPolicySettings::class);
        $action = $this->route('action');
        $requiresCorrectionProof = $action instanceof Action
            && in_array($action->financementStatus(), [
                Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                Action::FINANCEMENT_REJETE_DAF,
            ], true);

        return [
            'source_financement' => ['required', 'string', 'min:2', 'max:255'],
            'commentaire_financement' => ['required', 'string', 'min:5', 'max:3000'],
            'justificatif_financement' => [
                $requiresCorrectionProof ? 'required' : 'nullable',
                'file',
                'max:'.$documentPolicy->maxUploadKilobytes(),
                $documentPolicy->mimesRule(),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $action = $this->route('action');
                if (! $action instanceof Action || $this->hasFile('justificatif_financement')) {
                    return;
                }

                $hasFinancingProof = $action->justificatifs()
                    ->whereIn('categorie', ['financement', 'financement_daf'])
                    ->exists();

                if (! $hasFinancingProof) {
                    $validator->errors()->add(
                        'justificatif_financement',
                        'Une piece justificative est obligatoire avant la soumission du dossier a la DAF.'
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source_financement' => trim((string) $this->input('source_financement')),
            'commentaire_financement' => trim((string) $this->input('commentaire_financement')),
        ]);
    }
}
