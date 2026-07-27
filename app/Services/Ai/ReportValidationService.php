<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReportValidationService
{
    public function __construct(
        private readonly ReportTemplateConformityService $conformity
    ) {}

    public function validate(AiGeneratedReport $report, string $content, ?User $user = null): AiGeneratedReport
    {
        $this->conformity->apply($report, $content);
        $report->refresh();

        if (! $report->isTemplateConforming()) {
            throw ValidationException::withMessages([
                'content' => 'Le rapport ne peut pas etre valide : '.implode(' ', $report->conformity_issues ?? []),
            ]);
        }

        $report->forceFill([
            'validated_content' => trim($content),
            'status' => AiGeneratedReport::STATUS_VALIDATED,
        ])->save();

        return $report;
    }
}
