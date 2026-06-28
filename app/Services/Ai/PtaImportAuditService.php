<?php

namespace App\Services\Ai;

use App\Models\AiImportAudit;
use App\Models\AiImportBatch;
use App\Models\User;
use Illuminate\Http\Request;

class PtaImportAuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $action,
        ?AiImportBatch $batch = null,
        ?User $user = null,
        ?Request $request = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AiImportAudit {
        return AiImportAudit::query()->create([
            'user_id' => $user?->id,
            'batch_id' => $batch?->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
