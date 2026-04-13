<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\Pta;
use App\Models\User;
use App\Services\ActionManagementSettings;
use App\Services\Actions\ActionIndicatorService;
use App\Services\Actions\ActionTrackingService;
use App\Services\DocumentPolicySettings;
use App\Services\DynamicReferentialSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreActionRequest extends FormRequest
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
            'pta_id' => ['required', 'integer', 'exists:ptas,id'],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'type_cible' => ['required', Rule::in(app(DynamicReferentialSettings::class)->actionTargetTypeCodes())],
            'unite_cible' => ['nullable', 'string', 'max:100'],
            'quantite_cible' => ['nullable', 'numeric', 'min:0.0001'],
            'resultat_attendu' => ['nullable', 'string'],
            'criteres_validation' => ['nullable', 'string'],
            'livrable_attendu' => ['nullable', 'string'],

            'date_debut' => ['required', 'date', 'date_format:Y-m-d'],
            'date_fin' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
            'frequence_execution' => ['required', Rule::in(ActionTrackingService::executionFrequencyOptions())],
            'date_echeance' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
            'responsable_id' => ['required', 'integer', 'exists:users,id'],
            'contexte_action' => ['nullable', Rule::in(array_keys(Action::contextOptions()))],
            'origine_action' => ['nullable', Rule::in(array_keys(Action::originOptions()))],

            'statut' => ['nullable', Rule::in(['non_demarre', 'en_cours', 'suspendu', 'termine', 'annule'])],
            'statut_dynamique' => ['nullable', Rule::in(ActionTrackingService::dynamicStatusOptions())],
            'seuil_alerte_progression' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'risques' => ['nullable', 'string'],
            'mesures_preventives' => ['nullable', 'string'],

            'kpi_libelle' => ['nullable', 'string', 'max:255'],
            'kpi_unite' => ['nullable', 'string', 'max:30'],
            'kpi_cible' => ['nullable', 'numeric', 'min:0'],
            'kpi_seuil_alerte' => ['nullable', 'numeric', 'min:0'],
            'kpi_periodicite' => ['required', Rule::in(ActionIndicatorService::PERIODICITY_OPTIONS)],
            'kpi_est_a_renseigner' => ['required', 'boolean'],

            'financement_requis' => ['required', 'boolean'],
            'ressource_main_oeuvre' => ['nullable', 'boolean'],
            'ressource_equipement' => ['nullable', 'boolean'],
            'ressource_partenariat' => ['nullable', 'boolean'],
            'ressource_autres' => ['nullable', 'boolean'],
            'ressource_autres_details' => ['nullable', 'string'],
            'description_financement' => ['nullable', 'string'],
            'source_financement' => ['nullable', 'string', 'max:255'],
            'montant_estime' => ['nullable', 'numeric', 'min:0'],

            'justificatif_financement' => [
                'nullable',
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
            'date_fin.after_or_equal' => 'La date de fin doit etre superieure ou egale a la date de debut.',
            'date_echeance.after_or_equal' => 'La date d echeance doit etre superieure ou egale a la date de debut.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $contexteAction = $this->input('contexte_action');
        $contexteAction = $contexteAction === null || $contexteAction === ''
            ? Action::CONTEXT_PILOTAGE
            : (string) $contexteAction;

        $origineAction = $this->input('origine_action');
        $origineAction = $origineAction === null || $origineAction === ''
            ? ($contexteAction === Action::CONTEXT_OPERATIONNEL ? Action::ORIGIN_INTERNE : Action::ORIGIN_PTA)
            : (string) $origineAction;

        $this->merge([
            'contexte_action' => $contexteAction,
            'origine_action' => $origineAction,
            'financement_requis' => $this->boolean('financement_requis'),
            'ressource_main_oeuvre' => $this->boolean('ressource_main_oeuvre'),
            'ressource_equipement' => $this->boolean('ressource_equipement'),
            'ressource_partenariat' => $this->boolean('ressource_partenariat'),
            'ressource_autres' => $this->boolean('ressource_autres'),
            'kpi_est_a_renseigner' => $this->boolean('kpi_est_a_renseigner', true),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = (string) $this->input('type_cible');
            $actionManagementSettings = app(ActionManagementSettings::class);

            if (! $actionManagementSettings->manualSuspendEnabled()
                && (string) $this->input('statut') === ActionTrackingService::STATUS_SUSPENDU) {
                $validator->errors()->add(
                    'statut',
                    'Le statut suspendu est desactive par la politique metier actuelle.'
                );
            }

            if ($type === 'quantitative') {
                if (trim((string) $this->input('unite_cible')) === '') {
                    $validator->errors()->add(
                        'unite_cible',
                        'L unite de la cible est obligatoire pour une action quantitative.'
                    );
                }

                if ($this->input('quantite_cible') === null || (float) $this->input('quantite_cible') <= 0) {
                    $validator->errors()->add(
                        'quantite_cible',
                        'La quantite cible est obligatoire pour une action quantitative.'
                    );
                }
            } else {
                if (trim((string) $this->input('resultat_attendu')) === '') {
                    $validator->errors()->add(
                        'resultat_attendu',
                        'Le resultat attendu est obligatoire pour une action qualitative.'
                    );
                }
                if (trim((string) $this->input('criteres_validation')) === '') {
                    $validator->errors()->add(
                        'criteres_validation',
                        'Les criteres de validation sont obligatoires pour une action qualitative.'
                    );
                }
                if (trim((string) $this->input('livrable_attendu')) === '') {
                    $validator->errors()->add(
                        'livrable_attendu',
                        'Le livrable attendu est obligatoire pour une action qualitative.'
                    );
                }
            }

            if ($actionManagementSettings->riskPlanRequired()) {
                if (trim((string) $this->input('risques')) === '') {
                    $validator->errors()->add(
                        'risques',
                        'Les risques sont obligatoires selon la politique metier des actions.'
                    );
                }

                if (trim((string) $this->input('mesures_preventives')) === '') {
                    $validator->errors()->add(
                        'mesures_preventives',
                        'Les mesures preventives sont obligatoires selon la politique metier des actions.'
                    );
                }
            }

            $financementRequis = (bool) $this->boolean('financement_requis');
            $hasResource = $financementRequis
                || $this->boolean('ressource_main_oeuvre')
                || $this->boolean('ressource_equipement')
                || $this->boolean('ressource_partenariat')
                || $this->boolean('ressource_autres');

            if (! $hasResource) {
                $validator->errors()->add(
                    'ressource_main_oeuvre',
                    'Au moins une ressource mobilisee doit etre definie.'
                );
            }

            if ($this->boolean('ressource_autres')
                && trim((string) $this->input('ressource_autres_details')) === '') {
                $validator->errors()->add(
                    'ressource_autres_details',
                    'Veuillez preciser les autres ressources mobilisees.'
                );
            }

            $descriptionFinancement = $this->input('description_financement');
            $sourceFinancement = $this->input('source_financement');
            $montant = $this->input('montant_estime');

            if ($financementRequis) {
                if ($descriptionFinancement === null || trim((string) $descriptionFinancement) === '') {
                    $validator->errors()->add(
                        'description_financement',
                        'La description du financement est obligatoire quand le financement est requis.'
                    );
                }

                if ($sourceFinancement === null || trim((string) $sourceFinancement) === '') {
                    $validator->errors()->add(
                        'source_financement',
                        'La source de financement est obligatoire quand le financement est requis.'
                    );
                }

                if (! $this->hasFile('justificatif_financement')) {
                    $validator->errors()->add(
                        'justificatif_financement',
                        'Le justificatif de financement est obligatoire.'
                    );
                }
            } elseif ($montant !== null && (float) $montant > 0) {
                $validator->errors()->add(
                    'montant_estime',
                    'Le montant estime ne doit pas etre renseigne si aucun financement n est requis.'
                );
            }

            $indicatorTarget = $this->input('kpi_cible');
            if (($indicatorTarget === null || $indicatorTarget === '') && $type === 'quantitative') {
                $indicatorTarget = $this->input('quantite_cible');
            }
            $indicatorThreshold = $this->input('kpi_seuil_alerte');
            if ($indicatorTarget !== null
                && $indicatorThreshold !== null
                && (float) $indicatorThreshold > (float) $indicatorTarget) {
                $validator->errors()->add(
                    'kpi_seuil_alerte',
                    'Le seuil d alerte de l indicateur ne doit pas depasser sa cible.'
                );
            }

            $pta = Pta::query()->find((int) $this->input('pta_id'));
            $responsableId = $this->input('responsable_id');
            $contexteAction = (string) $this->input('contexte_action', Action::CONTEXT_PILOTAGE);

            if ($pta !== null && $responsableId !== null) {
                $responsable = User::query()->find((int) $responsableId);

                if ($responsable === null || ! (bool) ($responsable->is_active ?? true)) {
                    $validator->errors()->add(
                        'responsable_id',
                        'Le responsable doit etre un utilisateur actif.'
                    );
                } elseif ($contexteAction !== Action::CONTEXT_OPERATIONNEL
                    && ! $responsable->hasRole(User::ROLE_AGENT)
                ) {
                    $validator->errors()->add(
                        'responsable_id',
                        'Le responsable doit avoir le role agent pour une action de pilotage.'
                    );
                } elseif ($responsable->direction_id !== null
                    && (int) $responsable->direction_id !== (int) $pta->direction_id
                ) {
                    $validator->errors()->add(
                        'responsable_id',
                        'Le responsable doit appartenir a la meme direction que le PTA.'
                    );
                } elseif ($responsable->service_id !== null
                    && (int) $responsable->service_id !== (int) $pta->service_id
                ) {
                    $validator->errors()->add(
                        'responsable_id',
                        'Le responsable doit appartenir au meme service que le PTA.'
                    );
                }
            }
        });
    }
}
