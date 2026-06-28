<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use App\Models\User;

class ReportValidationService
{
    public function validate(AiGeneratedReport $report, string $content, ?User $user = null): AiGeneratedReport
    {
        $report->forceFill([
            'validated_content' => trim($content),
            'status' => AiGeneratedReport::STATUS_VALIDATED,
        ])->save();

        return $report;
    }
}
