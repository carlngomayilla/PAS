<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\SousAction;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Services\Workflow\DeadlineExtensionChangeSet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDeadlineExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $action = $this->route('action');

        return $user instanceof User
            && $action instanceof Action
            && $user->can('requestDeadlineExtension', $action);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $documentPolicy = app(DocumentPolicySettings::class);
        $selectedFields = $this->selectedFields();
        $isSubAction = (int) $this->input('sous_action_id', 0) > 0;

        return [
            'sous_action_id' => ['nullable', 'integer', 'exists:sous_actions,id'],
            'change_fields' => ['required', 'array', 'min:1'],
            'change_fields.*' => ['required', 'string', 'distinct', Rule::in(DeadlineExtensionChangeSet::allowedFields($isSubAction))],
            'requested_deadline' => [Rule::requiredIf(in_array(DeadlineExtensionChangeSet::FIELD_DEADLINE, $selectedFields, true)), 'nullable', 'date_format:Y-m-d', 'after:today'],
            'requested_libelle' => [Rule::requiredIf(in_array(DeadlineExtensionChangeSet::FIELD_LABEL, $selectedFields, true)), 'nullable', 'string', 'max:255'],
            'requested_description' => ['nullable', 'string', 'max:5000'],
            'requested_responsable_ids' => [Rule::requiredIf(in_array(DeadlineExtensionChangeSet::FIELD_RESPONSIBLES, $selectedFields, true)), 'nullable', 'array', 'min:1'],
            'requested_responsable_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'requested_date_debut' => [Rule::requiredIf(in_array(DeadlineExtensionChangeSet::FIELD_START_DATE, $selectedFields, true)), 'nullable', 'date_format:Y-m-d'],
            'requested_resultat_attendu' => ['nullable', 'string', 'max:5000'],
            'requested_priorite' => [Rule::requiredIf(in_array(DeadlineExtensionChangeSet::FIELD_PRIORITY, $selectedFields, true)), 'nullable', 'string', 'max:30'],
            'requested_indicateurs_attendus' => ['nullable', 'string', 'max:5000'],
            'requested_observations' => ['nullable', 'string', 'max:5000'],
            'requested_livrable_attendu' => ['nullable', 'string', 'max:5000'],
            'requested_unite' => [Rule::requiredIf(in_array(DeadlineExtensionChangeSet::FIELD_UNIT, $selectedFields, true)), 'nullable', 'string', 'max:100'],
            'requested_cible_prevue' => [Rule::requiredIf(in_array(DeadlineExtensionChangeSet::FIELD_TARGET, $selectedFields, true)), 'nullable', 'numeric', 'min:0', 'max:99999999999'],
            'motif' => ['required', 'string', 'min:5', 'max:255'],
            'justification' => ['required', 'string', 'min:10', 'max:5000'],
            'piece_justificative' => [
                'required',
                'file',
                'max:'.$documentPolicy->maxUploadKilobytes(),
                $documentPolicy->mimesRule(),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $action = $this->actionForValidation();
                if (! $action instanceof Action) {
                    return;
                }

                $sousAction = $this->selectedSousAction($action, $validator);
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $fields = $this->selectedFields();
                $this->validateResponsibleScope($validator, $action, $fields);
                $this->validateDates($validator, $action, $sousAction, $fields);

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $changeSet = app(DeadlineExtensionChangeSet::class);
                $changes = $changeSet->requestedChanges($this->all(), $sousAction instanceof SousAction);
                $original = $changeSet->snapshot($action, $sousAction, array_keys($changes));
                if ($this->normalized($changes) === $this->normalized($original)) {
                    $validator->errors()->add('change_fields', 'Au moins une valeur demandée doit être différente de la valeur actuelle.');
                }
            },
        ];
    }

    protected function actionForValidation(): ?Action
    {
        $action = $this->route('action');

        return $action instanceof Action ? $action : null;
    }

    protected function selectedSousAction(Action $action, Validator $validator): ?SousAction
    {
        $user = $this->user();
        $isAssignedSubActionAgent = $user instanceof User
            && $user->isAgent()
            && ! $action->isResponsible($user);
        $sousActionId = (int) $this->input('sous_action_id', 0);
        if ($sousActionId <= 0) {
            if ($isAssignedSubActionAgent) {
                $validator->errors()->add('sous_action_id', 'Selectionnez la sous-action qui vous est affectee.');
            }

            return null;
        }

        $sousAction = $action->sousActions()->whereKey($sousActionId)->first();
        if (! $sousAction instanceof SousAction) {
            $validator->errors()->add('sous_action_id', 'La sous-action sélectionnée ne correspond pas à cette action.');

            return null;
        }

        if ($isAssignedSubActionAgent && (int) $sousAction->agent_id !== (int) $user->id) {
            $validator->errors()->add('sous_action_id', 'Vous ne pouvez demander une modification que pour votre sous-action.');

            return null;
        }

        return $sousAction;
    }

    /** @return list<string> */
    protected function selectedFields(): array
    {
        return collect($this->input('change_fields', []))
            ->map(fn (mixed $field): string => trim((string) $field))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $fields */
    private function validateResponsibleScope(Validator $validator, Action $action, array $fields): void
    {
        if (! in_array(DeadlineExtensionChangeSet::FIELD_RESPONSIBLES, $fields, true)) {
            return;
        }

        $ids = collect($this->input('requested_responsable_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $eligibleCount = User::query()
            ->whereKey($ids)
            ->where('is_active', true)
            ->where('direction_id', (int) $action->pta?->direction_id)
            ->when($action->pta?->service_id !== null, fn ($query) => $query->where('service_id', (int) $action->pta?->service_id))
            ->count();

        if ($eligibleCount !== $ids->count()) {
            $validator->errors()->add('requested_responsable_ids', 'Chaque RMO choisi doit être actif et appartenir au même périmètre direction/service.');
        }

        if ((int) $this->input('sous_action_id', 0) > 0 && $ids->count() > 1) {
            $validator->errors()->add('requested_responsable_ids', 'Une sous-action ne peut avoir qu’un seul responsable.');
        }
    }

    /** @param list<string> $fields */
    private function validateDates(Validator $validator, Action $action, ?SousAction $sousAction, array $fields): void
    {
        $currentDeadline = $sousAction?->date_fin ?? $action->date_fin ?? $action->date_echeance ?? $action->echeance_cible;
        if ($currentDeadline === null) {
            $validator->errors()->add('requested_deadline', 'Aucune échéance de référence n’est définie pour cet élément.');

            return;
        }

        $requestedDeadline = in_array(DeadlineExtensionChangeSet::FIELD_DEADLINE, $fields, true)
            ? $this->input('requested_deadline')
            : $currentDeadline;
        if (in_array(DeadlineExtensionChangeSet::FIELD_DEADLINE, $fields, true)
            && is_string($requestedDeadline)
            && Carbon::parse($requestedDeadline)->startOfDay()->lessThanOrEqualTo(Carbon::parse($currentDeadline)->startOfDay())) {
            $validator->errors()->add('requested_deadline', 'La nouvelle échéance doit être postérieure à l’échéance actuelle.');
        }

        $startDate = in_array(DeadlineExtensionChangeSet::FIELD_START_DATE, $fields, true)
            ? $this->input('requested_date_debut')
            : ($sousAction?->date_debut ?? $action->date_debut);
        if ($startDate !== null && $requestedDeadline !== null
            && Carbon::parse($startDate)->startOfDay()->greaterThan(Carbon::parse($requestedDeadline)->startOfDay())) {
            $validator->errors()->add('requested_date_debut', 'La date de début doit être antérieure ou égale à l’échéance demandée.');
        }
    }

    private function normalized(array $values): string
    {
        foreach ($values as &$value) {
            if (is_array($value)) {
                sort($value);
            }
        }

        ksort($values);

        return json_encode($values, JSON_THROW_ON_ERROR);
    }
}
