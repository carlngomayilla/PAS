<?php

namespace App\Services\Exports;

use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\ExportTemplateVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExportTemplatePublisher
{
    public function publish(
        ExportTemplate $template,
        User $actor,
        ?string $note = null,
        bool $markAsDefault = false
    ): ExportTemplateVersion {
        return DB::transaction(function () use ($template, $actor, $note, $markAsDefault): ExportTemplateVersion {
            $scopeTemplates = $this->lockTemplateScope($template);
            $managedTemplate = $scopeTemplates->firstWhere('id', $template->getKey());

            if (! $managedTemplate instanceof ExportTemplate) {
                $managedTemplate = ExportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            }

            $wasPublished = $managedTemplate->isPublished();
            $willBeDefault = $markAsDefault || ($wasPublished && (bool) $managedTemplate->is_default);
            $assignments = ExportTemplateAssignment::query()
                ->where('export_template_id', $managedTemplate->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $baseAssignment = $this->ensureBaseAssignment($managedTemplate, $assignments, $actor);

            if ($willBeDefault) {
                $scopeTemplates
                    ->filter(fn (ExportTemplate $row): bool => (int) $row->id !== (int) $managedTemplate->id && (bool) $row->is_default)
                    ->each(function (ExportTemplate $row) use ($actor): void {
                        $row->forceFill([
                            'is_default' => false,
                            'updated_by' => $actor->id,
                        ])->save();
                    });
            }

            $defaultAssignmentIds = $wasPublished
                ? $assignments
                    ->filter(fn (ExportTemplateAssignment $assignment): bool => (bool) $assignment->is_active
                        && (bool) $assignment->is_default
                        && $this->identityMatches($managedTemplate, $assignment))
                    ->pluck('id')
                : collect();

            if ($willBeDefault) {
                $defaultAssignmentIds->push($baseAssignment->id);
            }
            $defaultAssignmentIds = $defaultAssignmentIds->unique()->values();

            $assignments->each(function (ExportTemplateAssignment $assignment) use ($defaultAssignmentIds, $actor): void {
                if ($defaultAssignmentIds->contains($assignment->id)) {
                    $this->demoteCompetingDefaults($assignment, $actor);
                }
            });

            $assignments->each(function (ExportTemplateAssignment $assignment) use ($managedTemplate, $defaultAssignmentIds, $actor): void {
                $identityMatches = $this->identityMatches($managedTemplate, $assignment);
                $assignment->forceFill([
                    'is_active' => $identityMatches,
                    'is_default' => $identityMatches && $defaultAssignmentIds->contains($assignment->id),
                    'updated_by' => $actor->id,
                ])->save();
            });

            $publishedAt = now();
            $managedTemplate->forceFill([
                'status' => ExportTemplate::STATUS_PUBLISHED,
                'is_active' => true,
                'is_default' => $willBeDefault,
                'updated_by' => $actor->id,
                'published_at' => $publishedAt,
            ])->save();

            return ExportTemplateVersion::query()->create([
                'export_template_id' => $managedTemplate->id,
                'version_number' => $this->nextVersionNumber($managedTemplate),
                'status' => ExportTemplate::STATUS_PUBLISHED,
                'note' => $this->noteOrDefault($note, 'Publication du template.'),
                'snapshot' => $this->snapshot($managedTemplate),
                'created_by' => $actor->id,
                'published_at' => $publishedAt,
            ]);
        });
    }

    public function archive(ExportTemplate $template, User $actor, ?string $note = null): ExportTemplateVersion
    {
        return DB::transaction(function () use ($template, $actor, $note): ExportTemplateVersion {
            $managedTemplate = ExportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());

            ExportTemplateAssignment::query()
                ->where('export_template_id', $managedTemplate->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (ExportTemplateAssignment $assignment) use ($actor): void {
                    $assignment->forceFill([
                        'is_default' => false,
                        'is_active' => false,
                        'updated_by' => $actor->id,
                    ])->save();
                });

            $managedTemplate->forceFill([
                'status' => ExportTemplate::STATUS_ARCHIVED,
                'is_default' => false,
                'is_active' => false,
                'updated_by' => $actor->id,
            ])->save();

            return ExportTemplateVersion::query()->create([
                'export_template_id' => $managedTemplate->id,
                'version_number' => $this->nextVersionNumber($managedTemplate),
                'status' => ExportTemplate::STATUS_ARCHIVED,
                'note' => $this->noteOrDefault($note, 'Archivage du template.'),
                'snapshot' => $this->snapshot($managedTemplate),
                'created_by' => $actor->id,
                'published_at' => null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(ExportTemplate $template, array $payload, User $actor): ExportTemplate
    {
        return DB::transaction(function () use ($template, $payload, $actor): ExportTemplate {
            $managedTemplate = ExportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());

            ExportTemplateAssignment::query()
                ->where('export_template_id', $managedTemplate->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (ExportTemplateAssignment $assignment) use ($actor): void {
                    $assignment->forceFill([
                        'is_default' => false,
                        'is_active' => false,
                        'updated_by' => $actor->id,
                    ])->save();
                });

            $managedTemplate->forceFill([
                ...$payload,
                'status' => ExportTemplate::STATUS_DRAFT,
                'is_default' => false,
                'is_active' => true,
                'published_at' => null,
                'updated_by' => $actor->id,
            ])->save();

            return $managedTemplate->fresh() ?? $managedTemplate;
        });
    }

    public function duplicate(ExportTemplate $template, User $actor): ExportTemplate
    {
        return DB::transaction(function () use ($template, $actor): ExportTemplate {
            $source = ExportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $copy = $source->replicate([
                'status',
                'is_default',
                'is_active',
                'published_at',
                'created_by',
                'updated_by',
            ]);

            $copy->code = $this->nextDuplicateCode((string) $source->code);
            $copy->name = $source->name.' - Copie';
            $copy->status = ExportTemplate::STATUS_DRAFT;
            $copy->is_default = false;
            $copy->is_active = true;
            $copy->created_by = $actor->id;
            $copy->updated_by = $actor->id;
            $copy->published_at = null;
            $copy->save();

            return $copy;
        });
    }

    public function restoreVersion(
        ExportTemplate $template,
        ExportTemplateVersion $version,
        User $actor,
        ?string $note = null
    ): ExportTemplateVersion {
        return DB::transaction(function () use ($template, $version, $actor, $note): ExportTemplateVersion {
            $managedTemplate = ExportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $managedVersion = ExportTemplateVersion::query()
                ->where('export_template_id', $managedTemplate->id)
                ->lockForUpdate()
                ->findOrFail($version->getKey());
            $snapshot = $managedVersion->snapshot ?? [];

            ExportTemplateAssignment::query()
                ->where('export_template_id', $managedTemplate->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (ExportTemplateAssignment $assignment) use ($actor): void {
                    $assignment->forceFill([
                        'is_default' => false,
                        'is_active' => false,
                        'updated_by' => $actor->id,
                    ])->save();
                });

            $managedTemplate->forceFill([
                'name' => (string) ($snapshot['name'] ?? $managedTemplate->name),
                'description' => ($snapshot['description'] ?? null) ?: null,
                'format' => (string) ($snapshot['format'] ?? $managedTemplate->format),
                'module' => (string) ($snapshot['module'] ?? $managedTemplate->module),
                'report_type' => (string) ($snapshot['report_type'] ?? $managedTemplate->report_type),
                'target_profile' => ($snapshot['target_profile'] ?? null) ?: null,
                'reading_level' => ($snapshot['reading_level'] ?? null) ?: null,
                'is_active' => true,
                'is_default' => false,
                'status' => ExportTemplate::STATUS_DRAFT,
                'blocks_config' => $snapshot['blocks_config'] ?? $managedTemplate->blocks_config,
                'layout_config' => $snapshot['layout_config'] ?? $managedTemplate->layout_config,
                'content_config' => $snapshot['content_config'] ?? $managedTemplate->content_config,
                'style_config' => $snapshot['style_config'] ?? $managedTemplate->style_config,
                'meta_config' => $snapshot['meta_config'] ?? $managedTemplate->meta_config,
                'updated_by' => $actor->id,
                'published_at' => null,
            ])->save();

            return ExportTemplateVersion::query()->create([
                'export_template_id' => $managedTemplate->id,
                'version_number' => $this->nextVersionNumber($managedTemplate),
                'status' => ExportTemplate::STATUS_DRAFT,
                'note' => $this->noteOrDefault($note, 'Restauration depuis v'.$managedVersion->version_number.'.'),
                'snapshot' => $this->snapshot($managedTemplate),
                'created_by' => $actor->id,
                'published_at' => null,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(ExportTemplate $template): array
    {
        return [
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'format' => $template->format,
            'module' => $template->module,
            'report_type' => $template->report_type,
            'target_profile' => $template->target_profile,
            'reading_level' => $template->reading_level,
            'status' => $template->status,
            'is_default' => $template->is_default,
            'is_active' => $template->is_active,
            'blocks_config' => $template->blocks_config,
            'layout_config' => $template->layout_config,
            'content_config' => $template->content_config,
            'style_config' => $template->style_config,
            'meta_config' => $template->meta_config,
        ];
    }

    /**
     * @return Collection<int, ExportTemplate>
     */
    private function lockTemplateScope(ExportTemplate $template): Collection
    {
        $query = ExportTemplate::query()
            ->where('module', $template->module)
            ->where('report_type', $template->report_type)
            ->where('format', $template->format);

        foreach (['target_profile', 'reading_level'] as $column) {
            $value = $template->{$column};
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  Collection<int, ExportTemplateAssignment>  $assignments
     */
    private function ensureBaseAssignment(
        ExportTemplate $template,
        Collection $assignments,
        User $actor
    ): ExportTemplateAssignment {
        $baseAssignment = $assignments->first(function (ExportTemplateAssignment $assignment) use ($template): bool {
            return $this->identityMatches($template, $assignment)
                && $assignment->target_profile === $template->target_profile
                && $assignment->reading_level === $template->reading_level
                && $assignment->direction_id === null
                && $assignment->service_id === null;
        });

        if ($baseAssignment instanceof ExportTemplateAssignment) {
            return $baseAssignment;
        }

        $baseAssignment = $template->assignments()->create([
            'module' => $template->module,
            'report_type' => $template->report_type,
            'format' => $template->format,
            'target_profile' => $template->target_profile,
            'reading_level' => $template->reading_level,
            'direction_id' => null,
            'service_id' => null,
            'is_default' => false,
            'is_active' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $assignments->push($baseAssignment);

        return $baseAssignment;
    }

    private function demoteCompetingDefaults(ExportTemplateAssignment $assignment, User $actor): void
    {
        $query = ExportTemplateAssignment::query()
            ->where('module', $assignment->module)
            ->where('report_type', $assignment->report_type)
            ->where('format', $assignment->format)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereKeyNot($assignment->id);

        foreach (['target_profile', 'reading_level', 'direction_id', 'service_id'] as $column) {
            $value = $assignment->{$column};
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        $query->orderBy('id')->lockForUpdate()->get()
            ->each(function (ExportTemplateAssignment $row) use ($actor): void {
                $row->forceFill([
                    'is_default' => false,
                    'updated_by' => $actor->id,
                ])->save();
            });
    }

    private function identityMatches(ExportTemplate $template, ExportTemplateAssignment $assignment): bool
    {
        return (string) $template->module === (string) $assignment->module
            && (string) $template->report_type === (string) $assignment->report_type
            && (string) $template->format === (string) $assignment->format;
    }

    private function nextVersionNumber(ExportTemplate $template): int
    {
        return ((int) ExportTemplateVersion::query()
            ->where('export_template_id', $template->id)
            ->max('version_number')) + 1;
    }

    private function noteOrDefault(?string $note, string $default): string
    {
        $note = trim((string) $note);

        return $note !== '' ? $note : $default;
    }

    private function nextDuplicateCode(string $baseCode): string
    {
        do {
            $code = Str::limit($baseCode, 90, '').'-copy-'.Str::lower(Str::random(6));
        } while (ExportTemplate::query()->where('code', $code)->exists());

        return $code;
    }
}
