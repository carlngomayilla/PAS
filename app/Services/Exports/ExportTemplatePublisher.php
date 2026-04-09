<?php

namespace App\Services\Exports;

use App\Models\ExportTemplate;
use App\Models\ExportTemplateVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ExportTemplatePublisher
{
    public function publish(ExportTemplate $template, User $actor, ?string $note = null): ExportTemplateVersion
    {
        $publishedAt = Carbon::now();
        $version = ExportTemplateVersion::query()->create([
            'export_template_id' => $template->id,
            'version_number' => ((int) $template->versions()->max('version_number')) + 1,
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'note' => $note ?: 'Publication du template.',
            'snapshot' => $this->snapshot($template),
            'created_by' => $actor->id,
            'published_at' => $publishedAt,
        ]);

        $template->forceFill([
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'is_active' => true,
            'updated_by' => $actor->id,
            'published_at' => $publishedAt,
        ])->save();

        return $version;
    }

    public function archive(ExportTemplate $template, User $actor, ?string $note = null): void
    {
        ExportTemplateVersion::query()->create([
            'export_template_id' => $template->id,
            'version_number' => ((int) $template->versions()->max('version_number')) + 1,
            'status' => ExportTemplate::STATUS_ARCHIVED,
            'note' => $note ?: 'Archivage du template.',
            'snapshot' => $this->snapshot($template),
            'created_by' => $actor->id,
            'published_at' => null,
        ]);

        $template->forceFill([
            'status' => ExportTemplate::STATUS_ARCHIVED,
            'is_active' => false,
            'updated_by' => $actor->id,
        ])->save();
    }

    public function duplicate(ExportTemplate $template, User $actor): ExportTemplate
    {
        $copy = $template->replicate([
            'status',
            'is_default',
            'is_active',
            'published_at',
            'created_by',
            'updated_by',
        ]);

        $copy->code = $this->nextDuplicateCode($template->code);
        $copy->name = $template->name.' - Copie';
        $copy->status = ExportTemplate::STATUS_DRAFT;
        $copy->is_default = false;
        $copy->is_active = true;
        $copy->created_by = $actor->id;
        $copy->updated_by = $actor->id;
        $copy->published_at = null;
        $copy->save();

        return $copy;
    }

    public function restoreVersion(ExportTemplate $template, ExportTemplateVersion $version, User $actor, ?string $note = null): ExportTemplateVersion
    {
        $snapshot = $version->snapshot ?? [];

        $template->forceFill([
            'name' => (string) ($snapshot['name'] ?? $template->name),
            'description' => ($snapshot['description'] ?? null) ?: null,
            'format' => (string) ($snapshot['format'] ?? $template->format),
            'module' => (string) ($snapshot['module'] ?? $template->module),
            'report_type' => (string) ($snapshot['report_type'] ?? $template->report_type),
            'target_profile' => ($snapshot['target_profile'] ?? null) ?: null,
            'reading_level' => ($snapshot['reading_level'] ?? null) ?: null,
            'is_active' => true,
            'status' => ExportTemplate::STATUS_DRAFT,
            'blocks_config' => $snapshot['blocks_config'] ?? $template->blocks_config,
            'layout_config' => $snapshot['layout_config'] ?? $template->layout_config,
            'content_config' => $snapshot['content_config'] ?? $template->content_config,
            'style_config' => $snapshot['style_config'] ?? $template->style_config,
            'meta_config' => $snapshot['meta_config'] ?? $template->meta_config,
            'updated_by' => $actor->id,
            'published_at' => null,
        ])->save();

        return ExportTemplateVersion::query()->create([
            'export_template_id' => $template->id,
            'version_number' => ((int) $template->versions()->max('version_number')) + 1,
            'status' => ExportTemplate::STATUS_DRAFT,
            'note' => $note ?: 'Restauration depuis v'.$version->version_number.'.',
            'snapshot' => $this->snapshot($template),
            'created_by' => $actor->id,
            'published_at' => null,
        ]);
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

    private function nextDuplicateCode(string $baseCode): string
    {
        return Str::limit($baseCode, 90, '').'-copy-'.Str::lower(Str::random(6));
    }
}
