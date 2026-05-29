<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Service;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Http\Requests\Concerns\RequiresPlanningWriter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePtaRequest extends FormRequest
{
    use RequiresPlanningWriter;

    protected function prepareForValidation(): void
    {
        $objectifId = $this->input('objectif_operationnel_id');
        if (($objectifId === null || $objectifId === '') && $this->filled('pao_id')) {
            $objectifId = ObjectifOperationnel::query()
                ->where('pao_id', (int) $this->input('pao_id'))
                ->orderBy('id')
                ->value('id');

            if ($objectifId === null) {
                $pao = Pao::query()->find((int) $this->input('pao_id'));
                $objectifId = $pao instanceof Pao
                    ? ObjectifOperationnel::ensureFromPao($pao, (string) $this->input('titre', ''))
                        ?->id
                    : null;
            }
        }

        $objectif = $objectifId !== null && $objectifId !== ''
            ? ObjectifOperationnel::query()->with(['pao', 'service:id,code,libelle,direction_id'])->find((int) $objectifId)
            : null;
        $pao = $objectif?->pao;
        $service = $objectif?->service;

        $merge = [];

        if ($objectif !== null && $pao !== null && $service !== null) {
            $merge = [
                'objectif_operationnel_id' => (int) $objectif->id,
                'pao_id' => (int) $pao->id,
                'direction_id' => (int) $pao->direction_id,
                'service_id' => (int) $service->id,
                'titre' => 'PTA - '.trim((string) ($service->code ?: $service->libelle ?: $service->id)),
            ];
        }

        $actions = $this->normalizeActionsInput((array) $this->input('actions', []));
        if ($actions !== []) {
            $merge['actions'] = $actions;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $requiresActions = $this->routeIs('workspace.pta.store');
        $documentPolicy = app(DocumentPolicySettings::class);

        return [
            'objectif_operationnel_id' => ['required', 'integer', 'exists:objectifs_operationnels,id'],
            'pao_id' => ['nullable', 'integer', 'exists:paos,id'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'titre' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'actions' => [$requiresActions ? 'required' : 'nullable', 'array', 'min:1'],
            'actions.*.libelle' => ['required', 'string', 'max:255'],
            'actions.*.description' => ['nullable', 'string'],
            'actions.*.resultat_attendu' => ['nullable', 'string'],
            'actions.*.date_debut' => ['required', 'date', 'date_format:Y-m-d'],
            'actions.*.date_fin' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:actions.*.date_debut'],
            'actions.*.mode_evaluation' => ['required', Rule::in([
                Action::MODE_QUANTITATIF,
                Action::MODE_SANS_QUANTITE,
                Action::MODE_SOUS_ACTIONS,
            ])],
            'actions.*.priorite' => ['nullable', 'string', 'max:50'],
            'actions.*.quantite_cible' => ['nullable', 'numeric', 'min:0.0001'],
            'actions.*.unite_cible' => ['nullable', 'string', 'max:100'],
            'actions.*.seuil_mode' => ['nullable', Rule::in(['unique', 'trimestriel'])],
            'actions.*.seuil_minimum' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'actions.*.seuil_t1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'actions.*.seuil_t2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'actions.*.seuil_t3' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'actions.*.seuil_t4' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'actions.*.justificatif_obligatoire' => ['nullable', 'boolean'],
            'actions.*.sous_actions' => ['nullable', 'array'],
            'actions.*.sous_actions.*.id' => ['nullable', 'integer', 'exists:sous_actions,id'],
            'actions.*.sous_actions.*.agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'actions.*.sous_actions.*.libelle' => ['nullable', 'string', 'max:255'],
            'actions.*.sous_actions.*.description' => ['nullable', 'string'],
            'actions.*.sous_actions.*.resultat_attendu' => ['nullable', 'string'],
            'actions.*.sous_actions.*.date_debut' => ['nullable', 'date', 'date_format:Y-m-d'],
            'actions.*.sous_actions.*.date_fin' => ['nullable', 'date', 'date_format:Y-m-d'],
            'actions.*.sous_actions.*.cible_prevue' => ['nullable', 'numeric', 'min:0'],
            'actions.*.sous_actions.*.unite' => ['nullable', 'string', 'max:100'],
            'actions.*.sous_actions.*.commentaire' => ['nullable', 'string'],
            'actions.*.montant_estime' => ['nullable', 'numeric', 'min:0'],
            'actions.*.nature_financement' => ['nullable', 'string', 'max:255'],
            'actions.*.source_financement' => ['nullable', 'string', 'max:255'],
            'actions.*.ressources_necessaires' => ['nullable', 'array'],
            'actions.*.ressources_necessaires.*' => ['string', Rule::in(array_keys(Action::resourceOptions()))],
            'actions.*.ressources_details' => ['nullable', 'string'],
            'actions.*.risque_potentiel' => ['nullable', 'string'],
            'actions.*.niveau_risque' => ['nullable', 'string', 'max:50'],
            'actions.*.mesures_preventives' => ['nullable', 'string'],
            'actions.*.commentaire_financement' => ['nullable', 'string'],
            'actions.*.justificatif_financement' => [
                'nullable',
                'file',
                'max:'.$documentPolicy->maxUploadKilobytes(),
                $documentPolicy->mimesRule(),
            ],
            'actions.*.financement_requis' => ['nullable', 'boolean'],
            'actions.*.rmo_ids' => ['required', 'array', 'min:1'],
            'actions.*.rmo_ids.*' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.unique' => 'Un PTA existe deja pour ce service.',
            'actions.*.libelle.required' => 'Le titre de l action est obligatoire.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'actions.*.libelle' => 'titre de l action',
            'actions.*.date_debut' => 'date de début',
            'actions.*.date_fin' => 'date de fin',
            'actions.*.rmo_ids' => 'RMO',
            'actions.*.montant_estime' => 'montant estimé',
            'actions.*.nature_financement' => 'nature du financement',
            'actions.*.source_financement' => 'source de financement',
            'actions.*.commentaire_financement' => 'commentaire financement',
            'actions.*.justificatif_financement' => 'pièce justificative de financement',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $objectif = ObjectifOperationnel::query()->find((int) $this->input('objectif_operationnel_id'));
            $pao = $objectif !== null ? Pao::query()->find((int) $objectif->pao_id) : null;
            $service = $this->filled('service_id')
                ? Service::query()->find((int) $this->input('service_id'))
                : null;
            $directionId = $this->filled('direction_id') ? (int) $this->input('direction_id') : null;

            if ($pao !== null && $directionId !== null && (int) $pao->direction_id !== $directionId) {
                $validator->errors()->add(
                    'direction_id',
                    'La direction du PTA doit correspondre a la direction du PAO.'
                );
            }

            if ($pao !== null && $pao->service_id === null) {
                // Le PAO global peut ne plus porter de service; l'objectif operationnel est la source de verite.
            }

            if ($objectif !== null && $service !== null && (int) $service->id !== (int) $objectif->service_id) {
                $validator->errors()->add(
                    'service_id',
                    'Le service du PTA doit reprendre le service destinataire de l objectif operationnel.'
                );
            }

            if ($pao !== null && $service !== null && (int) $service->direction_id !== (int) $pao->direction_id) {
                $validator->errors()->add('service_id', 'Le service selectionne doit appartenir a la direction du PAO parent.');
            }

            $actions = (array) $this->input('actions', []);
            $objectifEcheance = $objectif?->echeance;
            $serviceId = $objectif?->service_id;
            $directionId = $objectif?->direction_id;

            foreach ($actions as $index => $actionPayload) {
                if (! is_array($actionPayload)) {
                    continue;
                }

                $dateFin = (string) ($actionPayload['date_fin'] ?? '');
                if ($dateFin !== '' && $objectifEcheance !== null && $dateFin > $objectifEcheance->format('Y-m-d')) {
                    $validator->errors()->add(
                        "actions.{$index}.date_fin",
                        'La date de fin de l action ne peut pas dépasser l échéance de l objectif opérationnel.'
                    );
                }

                $subActions = is_array($actionPayload['sous_actions'] ?? null) ? $actionPayload['sous_actions'] : [];
                foreach ($subActions as $subIndex => $subActionPayload) {
                    if (! is_array($subActionPayload)) {
                        continue;
                    }

                    $subDateDebut = (string) ($subActionPayload['date_debut'] ?? '');
                    $subDateFin = (string) ($subActionPayload['date_fin'] ?? '');

                    if ($subDateDebut !== '' && $subDateFin !== '' && $subDateFin < $subDateDebut) {
                        $validator->errors()->add(
                            "actions.{$index}.sous_actions.{$subIndex}.date_fin",
                            'La date de fin de la sous-action doit être après sa date de début.'
                        );
                    }

                    if ($subDateFin !== '' && $dateFin !== '' && $subDateFin > $dateFin) {
                        $validator->errors()->add(
                            "actions.{$index}.sous_actions.{$subIndex}.date_fin",
                            'La date de fin de la sous-action ne peut pas dépasser la date de fin de l action.'
                        );
                    }

                    if ($subDateFin !== '' && $objectifEcheance !== null && $subDateFin > $objectifEcheance->format('Y-m-d')) {
                        $validator->errors()->add(
                            "actions.{$index}.sous_actions.{$subIndex}.date_fin",
                            'La date de fin de la sous-action ne peut pas dépasser l échéance de l objectif opérationnel.'
                        );
                    }
                }

                $rmoIds = collect($actionPayload['rmo_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values();

                if ($rmoIds->isEmpty()) {
                    continue;
                }

                $rmos = User::query()->whereIn('id', $rmoIds->all())->get()->keyBy('id');
                foreach ($rmoIds as $rmoId) {
                    $rmo = $rmos->get($rmoId);
                    if ($rmo === null || ! (bool) ($rmo->is_active ?? true)) {
                        $validator->errors()->add("actions.{$index}.rmo_ids", 'Chaque RMO doit être un utilisateur actif.');
                        break;
                    }

                    if ($directionId !== null && $rmo->direction_id !== null && (int) $rmo->direction_id !== (int) $directionId) {
                        $validator->errors()->add("actions.{$index}.rmo_ids", 'Chaque RMO doit appartenir à la direction de l objectif opérationnel.');
                        break;
                    }

                    if ($serviceId !== null && $rmo->service_id !== null && (int) $rmo->service_id !== (int) $serviceId) {
                        $validator->errors()->add("actions.{$index}.rmo_ids", 'Chaque RMO doit appartenir au service destinataire de l objectif opérationnel.');
                        break;
                    }
                }

                $modeEvaluation = (string) ($actionPayload['mode_evaluation'] ?? Action::MODE_SANS_QUANTITE);
                if ($modeEvaluation === Action::MODE_QUANTITATIF) {
                    $quantiteCible = $actionPayload['quantite_cible'] ?? null;
                    if ($quantiteCible === null || $quantiteCible === '' || (float) $quantiteCible <= 0) {
                        $validator->errors()->add(
                            "actions.{$index}.quantite_cible",
                            'La cible mesurable attendue est obligatoire pour une action quantitative.'
                        );
                    }

                    if (trim((string) ($actionPayload['unite_cible'] ?? '')) === '') {
                        $validator->errors()->add(
                            "actions.{$index}.unite_cible",
                            'L unite de mesure est obligatoire pour une action quantitative.'
                        );
                    }
                }

                $financementRequis = filter_var($actionPayload['financement_requis'] ?? false, FILTER_VALIDATE_BOOL);
                if ($financementRequis) {
                    if (($actionPayload['montant_estime'] ?? null) === null || $actionPayload['montant_estime'] === '') {
                        $validator->errors()->add(
                            "actions.{$index}.montant_estime",
                            'Le montant est obligatoire lorsque le financement est requis.'
                        );
                    }

                    if (trim((string) ($actionPayload['nature_financement'] ?? '')) === '') {
                        $validator->errors()->add(
                            "actions.{$index}.nature_financement",
                            'La nature du financement est obligatoire lorsque le financement est requis.'
                        );
                    }

                    if (! $this->hasFile("actions.{$index}.justificatif_financement")) {
                        $validator->errors()->add(
                            "actions.{$index}.justificatif_financement",
                            'La piece justificative du financement est obligatoire des la creation.'
                        );
                    }
                }
            }
        });
    }

    /**
     * @param array<int|string, mixed> $actions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeActionsInput(array $actions): array
    {
        return collect($actions)
            ->filter(fn ($action): bool => is_array($action))
            ->map(function (array $action): array {
                $rmoInput = $action['rmo_ids'] ?? $action['rmos'] ?? [];
                $rmoIds = collect(is_array($rmoInput) ? $rmoInput : [$rmoInput])
                    ->filter(fn ($id): bool => is_numeric($id))
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                $targetProvided = isset($action['quantite_cible'])
                    && $action['quantite_cible'] !== ''
                    && $action['quantite_cible'] !== null
                    && (float) $action['quantite_cible'] > 0;

                $mode = trim((string) ($action['mode_evaluation'] ?? ''));
                if ($mode === Action::MODE_MIXTE) {
                    $mode = $targetProvided ? Action::MODE_QUANTITATIF : Action::MODE_SOUS_ACTIONS;
                }

                $hasExplicitMode = in_array($mode, [
                    Action::MODE_QUANTITATIF,
                    Action::MODE_SANS_QUANTITE,
                    Action::MODE_SOUS_ACTIONS,
                ], true);
                if (! $hasExplicitMode) {
                    $mode = $targetProvided ? Action::MODE_QUANTITATIF : Action::MODE_SANS_QUANTITE;
                }

                if ($mode !== Action::MODE_QUANTITATIF) {
                    $action['quantite_cible'] = null;
                    $action['unite_cible'] = null;
                }

                $action['mode_evaluation'] = $mode;
                $action['rmo_ids'] = $rmoIds;
                $action['financement_requis'] = filter_var($action['financement_requis'] ?? false, FILTER_VALIDATE_BOOL);
                $action['justificatif_obligatoire'] = filter_var($action['justificatif_obligatoire'] ?? false, FILTER_VALIDATE_BOOL);
                $thresholdMode = (string) ($action['seuil_mode'] ?? 'unique');
                $action['seuil_mode'] = in_array($thresholdMode, ['unique', 'trimestriel'], true)
                    ? $thresholdMode
                    : 'unique';
                $action['seuil_minimum'] = ($action['seuil_minimum'] ?? '') === '' ? 80 : $action['seuil_minimum'];
                foreach (['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4'] as $thresholdKey) {
                    $action[$thresholdKey] = ($action[$thresholdKey] ?? '') === '' ? null : $action[$thresholdKey];
                }
                $action['sous_actions'] = collect($action['sous_actions'] ?? [])
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
                $action['ressources_necessaires'] = collect($action['ressources_necessaires'] ?? [])
                    ->filter(fn ($value): bool => is_string($value) && array_key_exists($value, Action::resourceOptions()))
                    ->unique()
                    ->values()
                    ->all();

                return $action;
            })
            ->values()
            ->all();
    }
}
