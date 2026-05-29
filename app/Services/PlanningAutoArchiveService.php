<?php

namespace App\Services;

use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PlanningAutoArchiveService
{
    public function __construct(
        private readonly PlanningArchiveSettings $settings
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'settings' => $this->settings->summary(),
            'counts' => [
                'paos' => $this->paoCandidatesQuery()->count(),
                'ptas' => $this->ptaCandidatesQuery()->count(),
            ],
            'cutoffs' => [
                'paos' => now()->subDays($this->settings->paoArchiveAfterDays())->toDateString(),
                'ptas' => now()->subDays($this->settings->ptaArchiveAfterDays())->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(bool $execute = false, ?User $actor = null): array
    {
        $summary = $this->summary();

        if (! $this->settings->enabled()) {
            return [
                'mode' => $execute ? 'execute' : 'dry-run',
                'enabled' => false,
                'archived' => ['paos' => 0, 'ptas' => 0],
                'summary' => $summary,
            ];
        }

        if (! $execute) {
            return [
                'mode' => 'dry-run',
                'enabled' => true,
                'archived' => ['paos' => 0, 'ptas' => 0],
                'summary' => $summary,
            ];
        }

        $archived = ['paos' => 0, 'ptas' => 0];

        DB::transaction(function () use (&$archived, $actor): void {
            $this->paoCandidatesQuery()
                ->orderBy('id')
                ->get()
                ->each(function (Pao $pao) use (&$archived, $actor): void {
                    $before = $pao->toArray();
                    $pao->forceFill([
                        'statut' => Pao::STATUS_ARCHIVE,
                        'valide_le' => now(),
                        'valide_par' => $actor?->id,
                    ])->save();

                    $this->audit('pao', $pao, $before, $pao->toArray(), $actor);
                    $archived['paos']++;
                });

            $this->ptaCandidatesQuery()
                ->orderBy('id')
                ->get()
                ->each(function (Pta $pta) use (&$archived, $actor): void {
                    $before = $pta->toArray();
                    $pta->forceFill([
                        'statut' => Pta::STATUS_ARCHIVE,
                        'valide_le' => now(),
                        'valide_par' => $actor?->id,
                    ])->save();

                    $this->audit('pta', $pta, $before, $pta->toArray(), $actor);
                    $archived['ptas']++;
                });
        });

        return [
            'mode' => 'execute',
            'enabled' => true,
            'archived' => $archived,
            'summary' => $this->summary(),
        ];
    }

    private function paoCandidatesQuery(): Builder
    {
        $cutoff = now()->subDays($this->settings->paoArchiveAfterDays());

        return Pao::query()
            ->where('statut', Pao::STATUS_CLOTURE)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where(function (Builder $dated): void {
                    $dated->whereNotNull('valide_le');
                })->where('valide_le', '<=', $cutoff)
                    ->orWhere(function (Builder $fallback) use ($cutoff): void {
                        $fallback->whereNull('valide_le')
                            ->where('updated_at', '<=', $cutoff);
                    });
            });
    }

    private function ptaCandidatesQuery(): Builder
    {
        $cutoff = now()->subDays($this->settings->ptaArchiveAfterDays());

        return Pta::query()
            ->where('statut', Pta::STATUS_CLOTURE)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where(function (Builder $dated): void {
                    $dated->whereNotNull('valide_le');
                })->where('valide_le', '<=', $cutoff)
                    ->orWhere(function (Builder $fallback) use ($cutoff): void {
                        $fallback->whereNull('valide_le')
                            ->where('updated_at', '<=', $cutoff);
                    });
            });
    }

    private function audit(string $module, Pao|Pta $entity, array $before, array $after, ?User $actor): void
    {
        JournalAudit::query()->create([
            'user_id' => $actor?->id,
            'module' => $module,
            'entite_type' => $entity::class,
            'entite_id' => (int) $entity->id,
            'action' => 'auto_archive',
            'ancienne_valeur' => $before,
            'nouvelle_valeur' => [
                ...$after,
                'motif' => 'Archivage automatique apres duree parametree.',
                'archive_settings' => $this->settings->summary(),
            ],
            'adresse_ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
