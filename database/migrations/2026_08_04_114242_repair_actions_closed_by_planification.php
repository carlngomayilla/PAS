<?php

use App\Services\Actions\ActionTrackingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repare les actions cloturees par la planification.
 *
 * `hasFinalValidation()` ne connaissait pas `validee_planification` : le recalcul
 * du statut dynamique ecrasait la cloture posee par `reviewActionByPlanification()`
 * et remettait l'action en `en_cours` / `en_retard`. Ces actions n'etaient donc
 * comptees ni comme terminees ni dans les taux de realisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('actions')
            ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_PLANIFICATION)
            ->where(function ($query): void {
                $query->where('statut', '!=', ActionTrackingService::STATUS_CLOTUREE)
                    ->orWhere('statut_dynamique', '!=', ActionTrackingService::STATUS_CLOTUREE);
            })
            ->update([
                'statut' => ActionTrackingService::STATUS_CLOTUREE,
                'statut_dynamique' => ActionTrackingService::STATUS_CLOTUREE,
                'date_fin_reelle' => DB::raw('COALESCE(date_fin_reelle, CURRENT_DATE)'),
                'cloture_le' => DB::raw('COALESCE(cloture_le, CURRENT_TIMESTAMP)'),
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Reparation de donnees : aucun retour arriere pertinent.
    }
};
