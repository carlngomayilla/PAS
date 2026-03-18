<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\JournalAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait RecordsAuditTrail
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    protected function recordAudit(
        Request $request,
        string $module,
        string $action,
        Model $model,
        ?array $before = null,
        ?array $after = null
    ): void {
        JournalAudit::query()->create([
            'user_id' => $request->user()?->id,
            'module' => $module,
            'entite_type' => $model::class,
            'entite_id' => (int) $model->getKey(),
            'action' => $action,
            'ancienne_valeur' => $before,
            'nouvelle_valeur' => $after,
            'adresse_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}

