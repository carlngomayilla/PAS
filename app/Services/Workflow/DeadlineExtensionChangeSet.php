<?php

namespace App\Services\Workflow;

use App\Enums\StatutEcheance;
use App\Enums\StatutRetard;
use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\SousAction;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DeadlineExtensionChangeSet
{
    public const FIELD_DEADLINE = 'deadline';

    public const FIELD_LABEL = 'libelle';

    public const FIELD_DESCRIPTION = 'description';

    public const FIELD_RESPONSIBLES = 'responsables';

    public const FIELD_START_DATE = 'date_debut';

    public const FIELD_EXPECTED_RESULT = 'resultat_attendu';

    public const FIELD_PRIORITY = 'priorite';

    public const FIELD_INDICATORS = 'indicateurs_attendus';

    public const FIELD_OBSERVATIONS = 'observations';

    public const FIELD_DELIVERABLE = 'livrable_attendu';

    public const FIELD_UNIT = 'unite';

    public const FIELD_TARGET = 'cible_prevue';

    /** @var list<string> */
    private const ACTION_FIELDS = [
        self::FIELD_DEADLINE,
        self::FIELD_LABEL,
        self::FIELD_DESCRIPTION,
        self::FIELD_RESPONSIBLES,
        self::FIELD_START_DATE,
        self::FIELD_EXPECTED_RESULT,
        self::FIELD_PRIORITY,
        self::FIELD_INDICATORS,
        self::FIELD_OBSERVATIONS,
        self::FIELD_DELIVERABLE,
    ];

    /** @var list<string> */
    private const SUB_ACTION_FIELDS = [
        self::FIELD_DEADLINE,
        self::FIELD_LABEL,
        self::FIELD_DESCRIPTION,
        self::FIELD_RESPONSIBLES,
        self::FIELD_START_DATE,
        self::FIELD_EXPECTED_RESULT,
        self::FIELD_DELIVERABLE,
        self::FIELD_UNIT,
        self::FIELD_TARGET,
    ];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::FIELD_DEADLINE => 'Échéance',
            self::FIELD_LABEL => 'Intitulé',
            self::FIELD_DESCRIPTION => 'Description',
            self::FIELD_RESPONSIBLES => 'RMO / responsables',
            self::FIELD_START_DATE => 'Date de début',
            self::FIELD_EXPECTED_RESULT => 'Résultat attendu',
            self::FIELD_PRIORITY => 'Priorité',
            self::FIELD_INDICATORS => 'Indicateurs attendus',
            self::FIELD_OBSERVATIONS => 'Observations',
            self::FIELD_DELIVERABLE => 'Livrable attendu',
            self::FIELD_UNIT => 'Unité de mesure',
            self::FIELD_TARGET => 'Cible prévue',
        ];
    }

    /** @return list<string> */
    public static function allowedFields(bool $isSubAction): array
    {
        return $isSubAction ? self::SUB_ACTION_FIELDS : self::ACTION_FIELDS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function requestedChanges(array $payload, bool $isSubAction): array
    {
        $selectedFields = collect($payload['change_fields'] ?? [])
            ->map(fn (mixed $field): string => trim((string) $field))
            ->filter(fn (string $field): bool => in_array($field, self::allowedFields($isSubAction), true))
            ->unique()
            ->values();

        $changes = [];
        foreach ($selectedFields as $field) {
            $changes[$field] = match ($field) {
                self::FIELD_DEADLINE => (string) $payload['requested_deadline'],
                self::FIELD_LABEL => trim((string) $payload['requested_libelle']),
                self::FIELD_DESCRIPTION => $this->nullableText($payload['requested_description'] ?? null),
                self::FIELD_RESPONSIBLES => collect($payload['requested_responsable_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all(),
                self::FIELD_START_DATE => (string) $payload['requested_date_debut'],
                self::FIELD_EXPECTED_RESULT => $this->nullableText($payload['requested_resultat_attendu'] ?? null),
                self::FIELD_PRIORITY => trim((string) $payload['requested_priorite']),
                self::FIELD_INDICATORS => $this->nullableText($payload['requested_indicateurs_attendus'] ?? null),
                self::FIELD_OBSERVATIONS => $this->nullableText($payload['requested_observations'] ?? null),
                self::FIELD_DELIVERABLE => $this->nullableText($payload['requested_livrable_attendu'] ?? null),
                self::FIELD_UNIT => trim((string) $payload['requested_unite']),
                self::FIELD_TARGET => (string) $payload['requested_cible_prevue'],
                default => null,
            };
        }

        return $changes;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function snapshot(Action $action, ?SousAction $sousAction, array $fields): array
    {
        $target = $sousAction ?? $action;
        $action->loadMissing('responsables:id');

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = match ($field) {
                self::FIELD_DEADLINE => $this->dateValue($sousAction?->date_fin ?? $action->date_fin ?? $action->date_echeance ?? $action->echeance_cible),
                self::FIELD_RESPONSIBLES => $sousAction instanceof SousAction
                    ? [(int) $sousAction->agent_id]
                    : $this->actionResponsibleIds($action),
                self::FIELD_START_DATE => $this->dateValue($target->date_debut),
                self::FIELD_TARGET => $target->cible_prevue === null ? null : (string) $target->cible_prevue,
                default => $target->getAttribute($field),
            };
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public function apply(DeadlineExtensionRequest $request): array
    {
        $action = $request->action;
        $sousAction = $request->sousAction;
        if (! $action instanceof Action) {
            throw ValidationException::withMessages(['decision' => 'L’action liée à la demande est indisponible.']);
        }

        if ($request->target_type === 'sous_action' && ! $sousAction instanceof SousAction) {
            throw ValidationException::withMessages(['decision' => 'La sous-action ciblée est supprimée ou indisponible. Aucune modification n’a été appliquée à l’action parente.']);
        }

        $changes = is_array($request->requested_changes) && $request->requested_changes !== []
            ? $request->requested_changes
            : [self::FIELD_DEADLINE => optional($request->approved_deadline ?? $request->requested_deadline)->format('Y-m-d')];
        $originalValues = is_array($request->original_values) && $request->original_values !== []
            ? $request->original_values
            : [self::FIELD_DEADLINE => optional($request->old_deadline)->format('Y-m-d')];
        $currentValues = $this->snapshot($action, $sousAction, array_keys($changes));
        $changedFields = collect(array_keys($changes))
            ->filter(fn (string $field): bool => $this->normalized($currentValues[$field] ?? null) !== $this->normalized($originalValues[$field] ?? null))
            ->map(fn (string $field): string => self::labels()[$field] ?? $field)
            ->values();

        if ($changedFields->isNotEmpty()) {
            throw ValidationException::withMessages([
                'decision' => 'Le dossier ne peut pas être appliqué car ces paramètres ont changé depuis la demande : '.$changedFields->implode(', ').'.',
            ]);
        }

        $target = $sousAction ?? $action;
        $attributes = [];
        foreach ($changes as $field => $value) {
            if ($field === self::FIELD_RESPONSIBLES) {
                continue;
            }

            if ($field === self::FIELD_DEADLINE) {
                $deadline = Carbon::parse((string) $value)->startOfDay();
                $attributes['date_fin'] = $deadline->toDateString();
                $attributes['statut_echeance'] = $deadline->isPast() ? StatutEcheance::Echue->value : StatutEcheance::NonEchue->value;
                $attributes['statut_retard'] = StatutRetard::DansLesDelais->value;
                if (! $sousAction instanceof SousAction) {
                    $attributes['date_echeance'] = $deadline->toDateString();
                    $attributes['echeance_cible'] = $deadline->toDateString();
                }

                continue;
            }

            $attributes[$field] = $value;
        }

        if (array_key_exists(self::FIELD_RESPONSIBLES, $changes)) {
            $responsibleIds = collect($changes[self::FIELD_RESPONSIBLES])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();
            $attributes[$sousAction instanceof SousAction ? 'agent_id' : 'responsable_id'] = $responsibleIds->first();
        }

        $target->forceFill($attributes)->save();

        if (! $sousAction instanceof SousAction && array_key_exists(self::FIELD_RESPONSIBLES, $changes)) {
            $primaryId = (int) $action->responsable_id;
            $action->responsables()->sync(
                collect($changes[self::FIELD_RESPONSIBLES])->mapWithKeys(fn (mixed $id): array => [
                    (int) $id => ['is_primary' => (int) $id === $primaryId],
                ])->all()
            );
        }

        $action->refresh();
        $sousAction?->refresh();

        return $this->snapshot($action, $sousAction, array_keys($changes));
    }

    /** @return list<int> */
    private function actionResponsibleIds(Action $action): array
    {
        return $action->responsables
            ->pluck('id')
            ->push($action->responsable_id)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function dateValue(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalized(mixed $value): string
    {
        if (is_array($value)) {
            sort($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
