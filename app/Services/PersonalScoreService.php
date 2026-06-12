<?php

namespace App\Services;

use App\Models\Action;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PersonalScoreService
{
    /**
     * Spec v3 (2026-06-11) : le score personnel reprend EXACTEMENT les deux KPI
     * portes par l'action elle-meme — taux_performance et taux_delai — sans
     * indicateur derive de la file de taches (« taches traitees » et
     * « criticite » supprimes). Performance pese 60 %, Delai 40 %.
     *
     * Le score s'applique au RMO (responsable de l'action) et au chef de service
     * (cf. {@see self::scopedActions()} pour le perimetre).
     *
     * @var array<string, int>
     */
    private const WEIGHTS = [
        'performance' => 60,
        'deadlines' => 40,
    ];

    /**
     * @param  string  $role  Role sidebar canonique (cf. UserWorkspaceService::specSidebarRole).
     * @return array{score: float, quality_label: string, components: array<string, array<string, mixed>>}
     */
    public function summarize(User $user, string $role): array
    {
        $actions = $this->scopedActions($user, $role);

        $components = [
            'performance' => $this->component(
                label: 'Performance',
                score: $this->averageKpi($actions, 'taux_performance'),
                weight: self::WEIGHTS['performance']
            ),
            'deadlines' => $this->component(
                label: 'Respect des delais',
                score: $this->averageKpi($actions, 'taux_delai'),
                weight: self::WEIGHTS['deadlines']
            ),
        ];

        $score = round($this->bound(
            collect($components)->sum(fn (array $component): float => (float) $component['weighted'])
        ), 1);

        return [
            'score' => $score,
            'quality_label' => $this->qualityLabel($score),
            'components' => $components,
        ];
    }

    /**
     * Actions servant de base au score :
     *   - tout le monde : les actions dont l'utilisateur est RMO ;
     *   - chef de service : EN PLUS, les actions des PTA de son service
     *     (perimetre d'encadrement). Le chef cumule donc ses propres actions et
     *     celles de son service.
     *
     * @return Collection<int, Action>
     */
    private function scopedActions(User $user, string $role): Collection
    {
        return Action::query()
            ->where(function (Builder $query) use ($user, $role): void {
                $query->forResponsable((int) $user->id);

                if ($role === 'chef' && $user->service_id !== null) {
                    $query->orWhereHas('pta', fn (Builder $ptaQuery) => $ptaQuery
                        ->where('service_id', (int) $user->service_id));
                }
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('taux_performance')
                    ->orWhereNotNull('taux_delai');
            })
            ->latest('updated_at')
            ->limit(100)
            ->get(['id', 'taux_performance', 'taux_delai']);
    }

    /**
     * Moyenne d'un KPI d'action (taux_performance / taux_delai) sur les actions
     * du perimetre. Les actions sans valeur sur ce KPI sont ignorees ; en
     * l'absence totale de donnee, on neutralise a 100 (pas de penalite par
     * defaut).
     *
     * @param  Collection<int, Action>  $actions
     */
    private function averageKpi(Collection $actions, string $column): float
    {
        $values = $actions
            ->map(fn (Action $action): ?float => $action->{$column} !== null
                ? (float) $action->{$column}
                : null)
            ->filter(fn (?float $value): bool => $value !== null)
            ->values();

        if ($values->isEmpty()) {
            return 100.0;
        }

        return round($this->bound((float) $values->avg()), 1);
    }

    /**
     * @return array{label: string, weight: int, score: float, weighted: float}
     */
    private function component(string $label, float $score, int $weight): array
    {
        $bounded = round($this->bound($score), 1);

        return [
            'label' => $label,
            'weight' => $weight,
            'score' => $bounded,
            'weighted' => round($bounded * ($weight / 100), 1),
        ];
    }

    private function qualityLabel(float $score): string
    {
        return match (true) {
            $score < 40 => 'Insuffisant',
            $score < 60 => 'Moyen',
            $score < 75 => 'Bon',
            $score < 90 => 'Tres bon',
            default => 'Excellent',
        };
    }

    private function bound(float $score): float
    {
        return max(0.0, min(100.0, $score));
    }
}
