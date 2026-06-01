<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Action;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint historique de validation chef d'une action.
 *
 * SUPPRIMÉ le 2026-05-31 : le workflow de suivi opérationnel a été retiré
 * pour être reconstruit from scratch. L'endpoint retourne désormais 410 Gone
 * pour les clients existants.
 */
class ActionValidationController extends Controller
{
    public function review(Request $request, Action $action): JsonResponse
    {
        return response()->json([
            'message' => 'Workflow de validation chef supprimé (refonte en cours). Endpoint indisponible.',
            'code' => 'workflow_removed',
        ], 410);
    }
}
