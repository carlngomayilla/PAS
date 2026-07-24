<?php

namespace App\Services\AiImport;

use App\Models\AiImportSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImportAuditService
{
    /**
     * @param  array<string,mixed>|null  $oldValues
     * @param  array<string,mixed>|null  $newValues
     */
    public function record(
        string $action,
        AiImportSession $session,
        ?User $user = null,
        ?Request $request = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        Log::info('AI import session audit', [
            'action' => $action,
            'ai_import_session_id' => $session->id,
            'user_id' => $user?->id,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
