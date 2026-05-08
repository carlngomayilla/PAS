<?php

namespace App\Observers;

use App\Services\Analytics\AnalyticsCacheVersionService;
use Illuminate\Database\Eloquent\Model;

class PlanningCacheObserver
{
    public function __construct(
        private readonly AnalyticsCacheVersionService $cacheVersion
    ) {
    }

    public function saved(Model $model): void
    {
        $this->cacheVersion->bumpAll();
    }

    public function deleted(Model $model): void
    {
        $this->cacheVersion->bumpAll();
    }

    public function restored(Model $model): void
    {
        $this->cacheVersion->bumpAll();
    }

    public function forceDeleted(Model $model): void
    {
        $this->cacheVersion->bumpAll();
    }
}