<?php

namespace App\Services\Exports;

use App\Models\Direction;
use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExportTemplateAssignmentService
{
    public function createInitial(ExportTemplate $template, User $actor): ExportTemplateAssignment
    {
        return DB::transaction(function () use ($template, $actor): ExportTemplateAssignment {
            $managedTemplate = ExportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $scope = $this->baseScope($managedTemplate);
            $existing = $this->exactAssignmentQuery($managedTemplate, $scope)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ExportTemplateAssignment) {
                if (! $managedTemplate->isPublished()) {
                    $existing->forceFill([
                        'is_default' => false,
                        'is_active' => false,
                        'updated_by' => $actor->id,
                    ])->save();
                }

                return $existing;
            }

            return $managedTemplate->assignments()->create([
                ...$scope,
                'is_default' => false,
                'is_active' => false,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(ExportTemplate $template, array $payload, User $actor): ExportTemplateAssignment
    {
        return DB::transaction(function () use ($template, $payload, $actor): ExportTemplateAssignment {
            $managedTemplate = ExportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $this->ensureIdentityMatches($managedTemplate, $payload);

            $scope = $this->normalizeScope($payload);
            $this->validateOrganizationalScope($scope);

            if ($this->exactAssignmentQuery($managedTemplate, $scope)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'target_profile' => 'Une affectation identique existe déjà pour ce template.',
                ]);
            }

            $isActive = filter_var($payload['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $isDefault = filter_var($payload['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $managedTemplate->isPublished() && ($isActive || $isDefault)) {
                throw ValidationException::withMessages([
                    'is_active' => 'Publiez d’abord le template avant d’activer une affectation.',
                ]);
            }

            if ($isDefault && ! $isActive) {
                throw ValidationException::withMessages([
                    'is_default' => 'Une affectation par défaut doit être active.',
                ]);
            }

            if ($isDefault) {
                $this->demoteDefaultAssignments($scope, null, $actor);
            }

            return $managedTemplate->assignments()->create([
                ...$scope,
                'is_default' => $isDefault,
                'is_active' => $isActive,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    public function toggle(ExportTemplateAssignment $assignment, User $actor): ExportTemplateAssignment
    {
        return DB::transaction(function () use ($assignment, $actor): ExportTemplateAssignment {
            $template = ExportTemplate::query()->lockForUpdate()->findOrFail($assignment->export_template_id);
            $managedAssignment = ExportTemplateAssignment::query()->lockForUpdate()->findOrFail($assignment->getKey());
            $willBeActive = ! (bool) $managedAssignment->is_active;

            if ($willBeActive && ! $template->isPublished()) {
                throw ValidationException::withMessages([
                    'is_active' => 'Une affectation ne peut être activée que sur un template publié.',
                ]);
            }

            if (! $this->identityMatches($template, $managedAssignment)) {
                throw ValidationException::withMessages([
                    'module' => 'Cette affectation historique ne correspond plus au template et doit rester inactive.',
                ]);
            }

            $managedAssignment->forceFill([
                'is_active' => $willBeActive,
                'is_default' => $willBeActive ? (bool) $managedAssignment->is_default : false,
                'updated_by' => $actor->id,
            ])->save();

            return $managedAssignment->fresh() ?? $managedAssignment;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureIdentityMatches(ExportTemplate $template, array $payload): void
    {
        foreach (['module', 'report_type', 'format'] as $field) {
            if ((string) ($payload[$field] ?? '') !== (string) $template->{$field}) {
                throw ValidationException::withMessages([
                    $field => 'L’affectation doit reprendre l’identité du template.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{module:string,report_type:string,format:string,target_profile:?string,reading_level:?string,direction_id:?int,service_id:?int}
     */
    private function normalizeScope(array $payload): array
    {
        return [
            'module' => trim((string) ($payload['module'] ?? '')),
            'report_type' => trim((string) ($payload['report_type'] ?? '')),
            'format' => trim((string) ($payload['format'] ?? '')),
            'target_profile' => $this->nullableString($payload['target_profile'] ?? null),
            'reading_level' => $this->nullableString($payload['reading_level'] ?? null),
            'direction_id' => $this->nullableId($payload['direction_id'] ?? null),
            'service_id' => $this->nullableId($payload['service_id'] ?? null),
        ];
    }

    /**
     * @return array{module:string,report_type:string,format:string,target_profile:?string,reading_level:?string,direction_id:null,service_id:null}
     */
    private function baseScope(ExportTemplate $template): array
    {
        return [
            'module' => (string) $template->module,
            'report_type' => (string) $template->report_type,
            'format' => (string) $template->format,
            'target_profile' => $template->target_profile ?: null,
            'reading_level' => $template->reading_level ?: null,
            'direction_id' => null,
            'service_id' => null,
        ];
    }

    /**
     * @param  array{direction_id:?int,service_id:?int}  $scope
     */
    private function validateOrganizationalScope(array &$scope): void
    {
        $direction = $scope['direction_id'] !== null
            ? Direction::query()->lockForUpdate()->find($scope['direction_id'])
            : null;
        if ($scope['direction_id'] !== null && (! $direction instanceof Direction || ! (bool) $direction->actif)) {
            throw ValidationException::withMessages([
                'direction_id' => 'La direction sélectionnée doit être active.',
            ]);
        }

        $service = $scope['service_id'] !== null
            ? Service::query()->lockForUpdate()->find($scope['service_id'])
            : null;
        if ($scope['service_id'] !== null && (! $service instanceof Service || ! (bool) $service->actif)) {
            throw ValidationException::withMessages([
                'service_id' => 'Le service sélectionné doit être actif.',
            ]);
        }

        if ($service instanceof Service && $scope['direction_id'] === null) {
            $scope['direction_id'] = (int) $service->direction_id;
        }

        if ($service instanceof Service && (int) $scope['direction_id'] !== (int) $service->direction_id) {
            throw ValidationException::withMessages([
                'service_id' => 'Le service sélectionné n’appartient pas à la direction choisie.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function exactAssignmentQuery(ExportTemplate $template, array $scope): Builder
    {
        $query = ExportTemplateAssignment::query()
            ->where('export_template_id', $template->id)
            ->where('module', $scope['module'])
            ->where('report_type', $scope['report_type'])
            ->where('format', $scope['format']);

        return $this->applyNullableScope($query, $scope);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function demoteDefaultAssignments(array $scope, ?int $exceptId, User $actor): void
    {
        $query = ExportTemplateAssignment::query()
            ->where('module', $scope['module'])
            ->where('report_type', $scope['report_type'])
            ->where('format', $scope['format'])
            ->where('is_default', true)
            ->where('is_active', true);
        $query = $this->applyNullableScope($query, $scope);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->orderBy('id')->lockForUpdate()->get()
            ->each(function (ExportTemplateAssignment $assignment) use ($actor): void {
                $assignment->forceFill([
                    'is_default' => false,
                    'updated_by' => $actor->id,
                ])->save();
            });
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function applyNullableScope(Builder $query, array $scope): Builder
    {
        foreach (['target_profile', 'reading_level', 'direction_id', 'service_id'] as $column) {
            $value = $scope[$column] ?? null;
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    private function identityMatches(ExportTemplate $template, ExportTemplateAssignment $assignment): bool
    {
        return (string) $template->module === (string) $assignment->module
            && (string) $template->report_type === (string) $assignment->report_type
            && (string) $template->format === (string) $assignment->format;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
