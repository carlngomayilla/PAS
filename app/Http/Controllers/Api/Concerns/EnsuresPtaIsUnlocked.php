<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;

trait EnsuresPtaIsUnlocked
{
    protected function assertPtaNotLocked(mixed $pta): ?JsonResponse
    {
        if ($pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Operation impossible.',
            ], 409);
        }

        return null;
    }
}
