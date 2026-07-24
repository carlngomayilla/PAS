<?php

namespace App\Services\Exports;

use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\User;

class ExportTemplateResolver
{
    public function resolve(User $user, string $module, string $reportType, string $format, ?string $readingLevel = null): ?ExportTemplate
    {
        $effectiveRole = $user->effectiveRoleCode();
        $readingLevelScore = $readingLevel !== null
            ? 'CASE WHEN reading_level IS NOT NULL THEN 10 ELSE 0 END'
            : '0';

        $assignment = ExportTemplateAssignment::query()
            ->with('template')
            ->where('module', $module)
            ->where('report_type', $reportType)
            ->where('format', $format)
            ->where('is_active', true)
            ->whereHas('template', function ($query): void {
                $query
                    ->where('status', ExportTemplate::STATUS_PUBLISHED)
                    ->where('is_active', true);
            })
            ->where(function ($query) use ($effectiveRole): void {
                $query->whereNull('target_profile')->orWhere('target_profile', $effectiveRole);
            })
            ->when($readingLevel !== null, function ($query) use ($readingLevel): void {
                $query->where(function ($scope) use ($readingLevel): void {
                    $scope->whereNull('reading_level')->orWhere('reading_level', $readingLevel);
                });
            })
            ->where(function ($query) use ($user): void {
                $query->whereNull('direction_id');
                if ($user->direction_id !== null) {
                    $query->orWhere('direction_id', $user->direction_id);
                }
            })
            ->where(function ($query) use ($user): void {
                $query->whereNull('service_id');
                if ($user->service_id !== null) {
                    $query->orWhere('service_id', $user->service_id);
                }
            })
            ->orderByRaw(
                '(CASE WHEN service_id IS NOT NULL THEN 40 ELSE 0 END
                + CASE WHEN direction_id IS NOT NULL THEN 30 ELSE 0 END
                + CASE WHEN target_profile IS NOT NULL THEN 20 ELSE 0 END
                + '.$readingLevelScore.'
                + CASE WHEN is_default = true THEN 5 ELSE 0 END) DESC'
            )
            ->orderByDesc('id')
            ->first();

        return $assignment?->template;
    }
}
