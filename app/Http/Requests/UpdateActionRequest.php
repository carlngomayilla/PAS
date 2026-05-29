<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\User;
use App\Services\ActionManagementSettings;
use App\Services\Actions\ActionIndicatorService;
use App\Services\Actions\ActionTrackingService;
use App\Services\DocumentPolicySettings;
use App\Http\Requests\Concerns\RequiresPlanningWriter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateActionRequest extends FormRequest
{
    use RequiresPlanningWriter;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $documentPolicy = app(DocumentPolicySettings::class);

        return [
            'objectif_operationnel_id' => ['required', 'integer', 'exists:objectifs_operationnels,id'],
            'pta_id' => ['required', 'integer', 'exists:ptas,id'],
            'pao_id' => ['required', 'integer', 'exists:paos,id'],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'type_cible' => ['nullable', Rule::in(['quantitative', 'qualitative', Action::MODE_SANS_QUANTITE])],
            'unite_cible' => ['nullable', 'string', 'max:100'],
            'quantite_cible' => ['nullable', 'numeric', 'min:0.0001'],
            'seuil_mode' => ['nullable', Rule::in(['unique', 'trimestriel'])],
            'seuil_minimum' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'seuil_t1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'seuil_t2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'seuil_t3' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'seuil_t4' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'justificatif_obligatoire' => ['nullable', 'boolean'],
            'sous_actions' => ['nullable', 'array'],
            'sous_actions.*.id' => ['nullable', 'integer', 'exists:sous_actions,id'],
            'sous_actions.*.agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'sous_actions.*.libelle' => ['nullable', 'string', 'max:255'],
            'sous_actions.*.description' => ['nullable', 'string'],
            'sous_actions.*.resultat_attendu' => ['nullable', 'string'],
            'sous_actions.*.date_debut' => ['nullable', 'date', 'date_format:Y-m-d'],
            'sous_actions.*.date_fin' => ['nullable', 'date', 'date_format:Y-m-d'],
            'sous_actions.*.cible_prevue' => ['nullable', 'numeric', 'min:0'],
            'sous_actions.*.unite' => ['nullable', 'string', 'max:100'],
            'sous_actions.*.commentaire' => ['nullable', 'string'],
            'resultat_attendu' => ['nullable', 'string'],
            'criteres_validation' => ['nullable', 'string'],
            'livrable_attendu' => ['nullable', 'string'],

            'date_debut' => ['nullable', 'date', 'date_format:Y-m-d'],
            'date_fin' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
            'frequence_execution' => ['nullable'], // champ supprime, garde nullable pour compat
            'date_echeance' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
            'responsable_id' => ['required', 'integer', 'exists:users,id'],
            'rmo_ids' => ['nullable', 'array'],
            'rmo_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'contexte_action' => ['nullable', Rule::in(array_keys(Action::contextOptions()))],
            'origine_action' => ['nullable', Rule::in(array_keys(Action::originOptions()))],

            'seuil_alerte_progression' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'kpi_libelle' => ['nullable', 'string', 'max:255'],
            'kpi_unite' => ['nullable', 'string', 'max:30'],
            'kpi_cible' => ['nullable', 'numeric', 'min:0'],
            'kpi_seuil_alerte' => ['nullable', 'numeric', 'min:0'],
            'kpi_periodicite' => ['required', Rule::in(ActionIndicatorService::PERIODICITY_OPTIONS)],
            'kpi_est_a_renseigner' => ['required', 'boolean'],

            'financement_requis' => ['required', 'boolean'],
            'ressources_necessaires' => ['nullable', 'array'],
            'ressources_necessaires.*' => ['string', Rule::in(array_keys(Action::resourceOptions()))],
            'ressources_details' => ['nullable', 'string'],
            'risque_potentiel' => ['nullable', 'string'],
            'niveau_risque' => ['nullable', 'string', 'max:50'],
            'mesures_preventives' => ['nullable', 'string'],
            'ressource_main_oeuvre' => ['nullable', 'boolean'],
            'ressource_equipement' => ['nullable', 'boolean'],
            'ressource_partenariat' => ['nullable', 'boolean'],
            'ressource_autres' => ['nullable', 'boolean'],
            'ressource_autres_details' => ['nullable', 'string'],
            'nature_financement' => ['nullable', 'string', 'max:255'],
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
            'libelle.required' => 'Le titre de l action est obligatoire.',
            'date_fin.after_or_equal' => 'La date de fin doit etre superieure ou egale a la date de debut.',
            'date_echeance.after_or_equal' => 'La date d echeance doit etre superieure ou egale a la date de debut.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'libelle' => 'titre de l action',
            'financement_requis' => 'besoin de financement',
            'montant_estime' => 'montant estime',
            'nature_financement' => 'nature du financement',
            'description_financement' => 'description du besoin de financement',
            'source_financement' => 'source de financement',
            'rmo_ids' => 'RMO',
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var Action|null $action */
        $action = $this->route('action');

        $type = trim((string) $this->input('type_cible', ''));
        if ($type === '') {
            $hasQuantitativeTarget = $this->input('quantite_cible') !== null
                && $this->input('quantite_cible') !== '';

            if (! $hasQuantitativeTarget) {
                $hasQuantitativeTarget = trim((string) $this->input('unite_cible', '')) !== '';
            }

            $type = $hasQuantitativeTarget
                ? 'quantitative'
                : ((string) $action?->mode_evaluation === Action::MODE_SANS_QUANTITE
                    ? Action::MODE_SANS_QUANTITE
                    : (string) ($action?->type_cible ?: 'qualitative'));
        }

        $contexteAction = $this->input('contexte_action');
        $contexteAction = $contexteAction === null || $contexteAction === ''
            ? (string) ($action?->contexte_action ?: Action::CONTEXT_PILOTAGE)
            : (string) $contexteAction;

        $origineAction = $this->input('origine_action');
        $origineAction = $origineAction === null || $origineAction === ''
            ? (string) ($action?->origine_action ?: ($contexteAction === Action::CONTEXT_OPERATIONNEL
                ? Action::ORIGIN_INTERNE
                : Action::ORIGIN_PTA))
            : (string) $origineAction;
        $rmoIds = collect((array) $this->input('rmo_ids', []))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($rmoIds === [] && is_numeric($this->input('responsable_id'))) {
            $rmoIds = [(int) $this->input('responsable_id')];
        }

        if ($rmoIds === [] && $action?->responsable_id !== null) {
            $rmoIds = [(int) $action->responsable_id];
        }

        $resources = collect((array) $this->input('ressources_necessaires', []))
            ->filter(fn ($value): bool => is_string($value) && array_key_exists($value, Action::resourceOptions()))
            ->unique()
            ->values()
            ->all();

        $objectifId = $this->input('objectif_operationnel_id') ?: $this->input('objectif_operationnel_id_selector') ?: $action?->objectif_operationnel_id;
        if (($objectifId === null || $objectifId === '') && is_numeric($this->input('pta_id'))) {
            $objectifId = Pta::query()
                ->whereKey((int) $this->input('pta_id'))
                ->value('objectif_operationnel_id');
        }
        if (($objectifId === null || $objectifId === '') && is_numeric($this->input('pao_id'))) {
            $objectifId = ObjectifOperationnel::query()
                ->where('pao_id', (int) $this->input('pao_id'))
                ->orderBy('id')
                ->value('id');
        }

        $objectif = $objectifId !== null && $objectifId !== ''
            ? ObjectifOperationnel::query()->find((int) $objectifId)
            : null;
        $paoId = $objectif?->pao_id ?: ($this->input('pao_id') ?: $this->input('pao_id_selector') ?: $action?->pao_id);
        $ptaId = $this->input('pta_id');
        if (($ptaId === null || $ptaId === '') && $objectif !== null) {
            $ptaId = Pta::query()
                ->where('objectif_operationnel_id', (int) $objectif->id)
                ->where('service_id', (int) $objectif->service_id)
                ->orderBy('id')
                ->value('id');
        }

        $this->merge([
            'objectif_operationnel_id' => $objectifId,
            'pta_id' => $ptaId,
            'pao_id' => $paoId,
            'type_cible' => $type,
            'seuil_mode' => in_array($this->input('seuil_mode', $action?->seuil_mode ?: 'unique'), ['unique', 'trimestriel'], true)
                ? (string) $this->input('seuil_mode', $action?->seuil_mode ?: 'unique')
                : 'unique',
            'seuil_minimum' => ($this->input('seuil_minimum') === null || $this->input('seuil_minimum') === '') ? ($action?->seuil_minimum ?? 80) : $this->input('seuil_minimum'),
            'seuil_t1' => ($this->input('seuil_t1') === null || $this->input('seuil_t1') === '') ? null : $this->input('seuil_t1'),
            'seuil_t2' => ($this->input('seuil_t2') === null || $this->input('seuil_t2') === '') ? null : $this->input('seuil_t2'),
            'seuil_t3' => ($this->input('seuil_t3') === null || $this->input('seuil_t3') === '') ? null : $this->input('seuil_t3'),
            'seuil_t4' => ($this->input('seuil_t4') === null || $this->input('seuil_t4') === '') ? null : $this->input('seuil_t4'),
            'justificatif_obligatoire' => $this->boolean('justificatif_obligatoire'),
            'sous_actions' => $this->normalizeSubActionsInput((array) $this->input('sous_actions', [])),
            'contexte_action' => $contexteAction,
            'origine_action' => $origineAction,
            'responsable_id' => $rmoIds[0] ?? $this->input('responsable_id'),
            'rmo_ids' => $rmoIds,
            'financement_requis' => $this->boolean('financement_requis'),
            'ressources_necessaires' => $resources,
            'ressource_main_oeuvre' => in_array('main_oeuvre', $resources, true) || in_array('ressources_humaines', $resources, true),
            'ressource_equipement' => in_array('ressources_materielles', $resources, true) || in_array('ressources_informatiques', $resources, true),
            'ressource_partenariat' => in_array('partenariat', $resources, true),
            'ressource_autres' => in_array('autres_ressources', $resources, true),
            'ressource_autres_details' => $this->input('ressources_details') ?: $this->input('ressource_autres_details'),
            'kpi_periodicite' => (string) $this->input('kpi_periodicite', 'mensuel'),
            'kpi_est_a_renseigner' => $this->boolean('kpi_est_a_renseigner', true),
        ]);
    }

    /**
     * @param array<int|string, mixed> $subActions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSubActionsInput(array $subActions): array
    {
        return collect($subActions)
            ->filter(fn ($subAction): bool => is_array($subAction))
            ->map(function (array $subAction): array {
                foreach (['id', 'agent_id', 'libelle', 'description', 'resultat_attendu', 'date_debut', 'date_fin', 'cible_prevue', 'unite', 'commentaire'] as $key) {
                    $subAction[$key] = $subAction[$key] ?? null;
                }

                return $subAction;
            })
            ->filter(function (array $subAction): bool {
                return collect(['libelle', 'description', 'resultat_attendu', 'cible_prevue', 'commentaire'])
                    ->contains(fn (string $key): bool => trim((string) ($subAction[$key] ?? '')) !== '');
            })
            ->values()
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actionManagementSettings = app(ActionManagementSettings::class);
            $action = $this->route('action');
            $action = $action instanceof Action ? $action : null;

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = (string) $this->input('type_cible');

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

            if ($this->boolean('financement_requis')) {
                if ($this->input('montant_estime') === null || $this->input('montant_estime') === '') {
                    $validator->errors()->add(
                        'montant_estime',
                        'Le montant estime est obligatoire lorsque le financement est requis.'
                    );
                }

                if (trim((string) $this->input('nature_financement', '')) === '') {
                    $validator->errors()->add(
                        'nature_financement',
                        'La nature du financement est obligatoire lorsque le financement est requis.'
                    );
                }

                if (! $this->hasFile('justificatif_financement') && ! $this->hasExistingFinancingJustificatif($action)) {
                    $validator->errors()->add(
                        'justificatif_financement',
                        'La piece justificative du financement est obligatoire.'
                    );
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $pta = Pta::query()->with('objectifOperationnel:id,echeance,service_id,direction_id,pao_id')->find((int) $this->input('pta_id'));
            $objectif = ObjectifOperationnel::query()->find((int) $this->input('objectif_operationnel_id'));
            $pao = Pao::query()->find((int) $this->input('pao_id'));
            $responsableId = $this->input('responsable_id');
            $contexteAction = (string) $this->input('contexte_action', Action::CONTEXT_PILOTAGE);

            if ($pta !== null && $objectif !== null) {
                if ((int) $objectif->pao_id !== (int) $pta->pao_id
                    || (int) $objectif->id !== (int) $pta->objectif_operationnel_id
                    || (int) $objectif->service_id !== (int) $pta->service_id
                ) {
                    $validator->errors()->add(
                        'objectif_operationnel_id',
                        'L objectif operationnel selectionne doit etre celui transmis au service dans le PTA.'
                    );
                }

                $dateEcheance = $this->input('date_echeance') ?: $this->input('date_fin');
                $objectifEcheance = $objectif->echeance;
                $ptaEcheance = $objectifEcheance;
                if ($dateEcheance && $objectifEcheance && $dateEcheance > (string) $objectifEcheance) {
                    $validator->errors()->add(
                        'date_echeance',
                        'L echéance de l action ne peut pas dépasser celle du PTA/PAO parent ('.$ptaEcheance.').'
                    );
                }
            }

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

            $rmoIds = collect((array) $this->input('rmo_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($pta !== null && $rmoIds->isEmpty()) {
                $validator->errors()->add('rmo_ids', 'Selectionnez au moins un RMO.');
            }

            if ($pta !== null && $rmoIds->isNotEmpty()) {
                $rmos = User::query()->whereIn('id', $rmoIds->all())->get()->keyBy('id');

                foreach ($rmoIds as $rmoId) {
                    $rmo = $rmos->get($rmoId);
                    if ($rmo === null || ! (bool) ($rmo->is_active ?? true)) {
                        $validator->errors()->add('rmo_ids', 'Tous les RMO doivent etre des utilisateurs actifs.');
                        break;
                    }

                    if ($contexteAction !== Action::CONTEXT_OPERATIONNEL && ! $rmo->hasRole(User::ROLE_AGENT)) {
                        $validator->errors()->add('rmo_ids', 'Chaque RMO doit avoir le role agent pour une action de pilotage.');
                        break;
                    }

                    if ($rmo->direction_id !== null && (int) $rmo->direction_id !== (int) $pta->direction_id) {
                        $validator->errors()->add('rmo_ids', 'Chaque RMO doit appartenir a la meme direction que le PTA.');
                        break;
                    }

                    if ($rmo->service_id !== null && (int) $rmo->service_id !== (int) $pta->service_id) {
                        $validator->errors()->add('rmo_ids', 'Chaque RMO doit appartenir au meme service que le PTA.');
                        break;
                    }
                }
            }
        });
    }

    private function hasExistingFinancingJustificatif(?Action $action): bool
    {
        if (! $action instanceof Action) {
            return false;
        }

        if (trim((string) ($action->justificatif_financement_path ?? '')) !== '') {
            return true;
        }

        return $action->justificatifs()
            ->where('categorie', 'financement')
            ->exists();
    }
}
