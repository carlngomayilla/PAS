<?php

namespace App\Observers;

use App\Models\Action;
use App\Models\JournalAudit;
use App\Services\Analytics\AnalyticsCacheVersionService;

class ActionObserver
{
    public function __construct(
        private readonly AnalyticsCacheVersionService $cacheVersion
    ) {
    }

    // Fields whose change affects long-term reports (history, exports).
    // Other changes (comments, resources, risks) only invalidate the dashboard.
    //
    // A38 — Ajout des champs d evaluation/cible qui impactent les KPI consolides
    // (seuil_minimum modifie la grille de scoring, evaluation_note influence
    // le rapport de validation, taux_realisation_global est la projection).
    private const REPORTING_FIELDS = [
        'pta_id', 'date_debut', 'date_fin', 'exercice_id',
        'statut_dynamique', 'statut_validation',
        'financement_statut', 'financement_requis',
        'progression_reelle', 'type_cible',
        'seuil_minimum', 'seuil_mode', 'seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4',
        'evaluation_note',
        'taux_realisation_global', 'taux_global', 'taux_atteinte_cible',
        'quantite_cible', 'mode_evaluation',
    ];

    public function created(Action $action): void
    {
        $this->cacheVersion->bumpAll();
    }

    public function updated(Action $action): void
    {
        $dirty = array_keys($action->getDirty());

        if (array_intersect($dirty, self::REPORTING_FIELDS)) {
            $this->cacheVersion->bumpAll();
        } else {
            $this->cacheVersion->bumpDashboard();
        }
    }

    public function deleted(Action $action): void
    {
        $this->cacheVersion->bumpAll();
        $this->audit($action, 'soft_delete');
    }

    public function restored(Action $action): void
    {
        $this->cacheVersion->bumpAll();
        $this->audit($action, 'restore');
    }

    public function forceDeleted(Action $action): void
    {
        $this->cacheVersion->bumpAll();
        $this->audit($action, 'force_delete');
    }

    private function audit(Action $action, string $event): void
    {
        JournalAudit::query()->create([
            'user_id' => auth()->id(),
            'module' => 'actions',
            'entite_type' => Action::class,
            'entite_id' => (int) $action->id,
            'action' => $event,
            'ancienne_valeur' => $action->getOriginal(),
            'nouvelle_valeur' => null,
            'adresse_ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}