<?php

namespace App\Services;

use App\Models\Action;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Justificatif;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

class OrganizationGovernanceService
{
    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\JournalAudit>
     */
    public function recentHistory(int $limit = 20): Collection
    {
        return JournalAudit::query()
            ->with('user:id,name,email')
            ->where('module', 'super_admin')
            ->where(function ($query): void {
                $query->where('action', 'like', 'organization_%')
                    ->orWhere('action', 'like', 'role_registry_%');
            })
            ->latest('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function simulateServiceMerge(?Service $source, ?Service $target): ?array
    {
        if (! $source instanceof Service || ! $target instanceof Service || (int) $source->id === (int) $target->id) {
            return null;
        }

        $ptaIds = $source->ptas()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $actionIds = Action::query()
            ->whereIn('pta_id', $ptaIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [
            'type' => 'service_merge',
            'title' => 'Simulation de fusion de services',
            'source_label' => $this->serviceLabel($source),
            'target_label' => $this->serviceLabel($target),
            'scope_alignment' => (int) $source->direction_id === (int) $target->direction_id ? 'intra_direction' : 'inter_direction',
            'impacts' => [
                'users' => $source->users()->count(),
                'ptas' => count($ptaIds),
                'actions' => count($actionIds),
                'justificatifs' => Justificatif::query()
                    ->where(function ($query) use ($actionIds): void {
                        $query->where(function ($actionQuery) use ($actionIds): void {
                            $actionQuery->where('justifiable_type', Action::class)
                                ->whereIn('justifiable_id', $actionIds);
                        });
                    })
                    ->count(),
            ],
            'warnings' => array_values(array_filter([
                (int) $source->direction_id !== (int) $target->direction_id
                    ? 'Les deux services n appartiennent pas a la meme direction.'
                    : null,
                $source->users()->where('role', User::ROLE_SERVICE)->count() > 0
                    ? 'Le service source porte des comptes de type chef de service.'
                    : null,
            ])),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function simulateServiceTransfer(?Service $service, ?Direction $targetDirection): ?array
    {
        if (! $service instanceof Service || ! $targetDirection instanceof Direction || (int) $service->direction_id === (int) $targetDirection->id) {
            return null;
        }

        $ptaIds = $service->ptas()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $actionCount = Action::query()->whereIn('pta_id', $ptaIds)->count();

        return [
            'type' => 'service_transfer',
            'title' => 'Simulation de rattachement de service',
            'source_label' => $this->serviceLabel($service),
            'target_label' => $targetDirection->code.' - '.$targetDirection->libelle,
            'impacts' => [
                'users' => $service->users()->count(),
                'ptas' => count($ptaIds),
                'actions' => $actionCount,
            ],
            'warnings' => array_values(array_filter([
                $service->ptas()->count() > 0
                    ? 'Le service porte deja des PTA. Le changement impactera les portefeuilles existants.'
                    : null,
                $service->users()->whereIn('role', [User::ROLE_SERVICE, User::ROLE_AGENT])->count() > 0
                    ? 'Les comptes rattaches devront etre revus apres le changement de direction.'
                    : null,
            ])),
        ];
    }

    private function serviceLabel(Service $service): string
    {
        $service->loadMissing('direction:id,code');

        return trim((string) ($service->direction?->code ?? '')).' / '.$service->code.' - '.$service->libelle;
    }
}
