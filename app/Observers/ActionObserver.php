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
    private const REPORTING_FIELDS = [
        'pta_id', 'date_debut', 'date_fin', 'exercice_id',
        'statut_dynamique', 'statut_validation',
        'financement_statut', 'financement_requis',
        'progression_reelle', 'type_cible',
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