<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Support\UiLabel;
use Illuminate\Http\JsonResponse;

trait EnsuresPtaIsUnlocked
{
    use FormatsWorkflowMessages;

    protected function assertPtaNotLocked(mixed $pta): ?JsonResponse
    {
        if ($pta?->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Operation'),
            ], 409);
        }

        return null;
    }
}
