<?php

namespace App\Services\Exports;

use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\User;

class ExportTemplateResolver
{
    public function __construct(
        private readonly \App\Services\RoleRegistryService $roleRegistry
    ) {
    }

    public function resolve(User $user, string $module, string $reportType, string $format, ?string $readingLevel = null): ?ExportTemplate
    {
        $effectiveRole = $user->effectiveRoleCode();

        $assignments = ExportTemplateAssignment::query()
            ->with('template')
            ->where('module', $module)
            ->where('report_type', $reportType)
            ->where('format', $format)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->filter(function (ExportTemplateAssignment $assignment) use ($user, $readingLevel, $effectiveRole): bool {
                $template = $assignment->template;
                if (! $template instanceof ExportTemplate || ! $template->isPublished()) {
                    return false;
                }

                if ($assignment->target_profile !== null && $assignment->target_profile !== $effectiveRole) {
                    return false;
                }

                if ($readingLevel !== null && $assignment->reading_level !== null && $assignment->reading_level !== $readingLevel) {
                    return false;
                }

                if ($assignment->direction_id !== null && (int) $assignment->direction_id !== (int) $user->direction_id) {
                    return false;
                }

                if ($assignment->service_id !== null && (int) $assignment->service_id !== (int) $user->service_id) {
                    return false;
                }

                return true;
            })
            ->sortByDesc(fn (ExportTemplateAssignment $assignment): int => $this->score($assignment, $user, $readingLevel, $effectiveRole))
            ->values();

        return $assignments->first()?->template;
    }

    private function score(ExportTemplateAssignment $assignment, User $user, ?string $readingLevel, string $effectiveRole): int
    {
        $score = 0;

        if ($assignment->service_id !== null && (int) $assignment->service_id === (int) $user->service_id) {
            $score += 40;
        }

        if ($assignment->direction_id !== null && (int) $assignment->direction_id === (int) $user->direction_id) {
            $score += 30;
        }

        if ($assignment->target_profile !== null && $assignment->target_profile === $effectiveRole) {
            $score += 20;
        }

        if ($readingLevel !== null && $assignment->reading_level !== null && $assignment->reading_level === $readingLevel) {
            $score += 10;
        }

        if ($assignment->is_default) {
            $score += 5;
        }

        return ($score * 100000) + (int) $assignment->id;
    }
}
