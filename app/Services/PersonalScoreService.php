<?php

namespace App\Services;

use App\Models\Action;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PersonalScoreService
{
    /**
     * @var array<string, int>
     */
    private const WEIGHTS = [
        'processed' => 35,
        'deadlines' => 30,
        'quality' => 25,
        'criticality' => 10,
    ];

    /**
     * @param Collection<int, array<string, mixed>> $openTasks
     * @return array{score: float, quality_label: string, components: array<string, array<string, mixed>>}
     */
    public function summarize(User $user, Collection $openTasks): array
    {
        $components = [
            'processed' => $this->component(
                label: 'Taches traitees',
                score: $this->processedScore($user, $openTasks),
                weight: self::WEIGHTS['processed']
            ),
            'deadlines' => $this->component(
                label: 'Respect des delais',
                score: $this->deadlineScore($openTasks),
                weight: self::WEIGHTS['deadlines']
            ),
            'quality' => $this->component(
                label: 'Qualite du traitement',
                score: $this->qualityScore($user),
                weight: self::WEIGHTS['quality']
            ),
            'criticality' => $this->component(
                label: 'Criticite / importance',
                score: $this->criticalityScore($openTasks),
                weight: self::WEIGHTS['criticality']
            ),
        ];

        $score = collect($components)
            ->sum(fn (array $component): float => (float) $component['weighted']);

        $qualityScore = (float) ($components['quality']['score'] ?? 100.0);

        return [
            'score' => round($this->bound($score), 1),
            'quality_label' => $this->qualityLabel($qualityScore),
            'components' => $components,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $openTasks
     */
    private function processedScore(User $user, Collection $openTasks): float
    {
        $open = $openTasks->count();
        $processed = $this->processedTaskCount($user);
        $total = $open + $processed;

        if ($total === 0) {
            return 100.0;
        }

        return round($this->bound(($processed / $total) * 100), 1);
    }

    private function processedTaskCount(User $user): int
    {
        $since = now()->subDays(90);

        $count = Action::query()
            ->forResponsable((int) $user->id)
            ->where(function ($query): void {
                $query
                    ->whereIn('statut_validation', [
                        ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                        ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                        ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                    ])
                    ->orWhereIn('statut_dynamique', ActionTrackingService::completedActionStatuses());
            })
            ->where('updated_at', '>=', $since)
            ->count();

        $count += SousAction::query()
            ->where('agent_id', (int) $user->id)
            ->whereIn('statut', ['realisee', 'en_attente_validation_chef', 'validee_chef', 'validee', 'cloturee'])
            ->where('updated_at', '>=', $since)
            ->count();

        foreach ([
            'evalue_par',
            'financement_daf_par',
            'financement_dg_par',
        ] as $column) {
            if (! Schema::hasColumn('actions', $column)) {
                continue;
            }

            $count += Action::query()
                ->where($column, (int) $user->id)
                ->where('updated_at', '>=', $since)
                ->count();
        }

        return $count;
    }

    /**
     * @param Collection<int, array<string, mixed>> $openTasks
     */
    private function deadlineScore(Collection $openTasks): float
    {
        $deadlinedTasks = $openTasks->filter(fn (array $task): bool => ($task['deadline_at'] ?? null) !== null);
        $total = $deadlinedTasks->count();

        if ($total === 0) {
            return 100.0;
        }

        $overdue = $deadlinedTasks->where('is_overdue', true)->count();

        return round($this->bound(100 - (($overdue / $total) * 100)), 1);
    }

    private function qualityScore(User $user): float
    {
        // Spec v2 (2026-05-28) : la note du chef et le KPI conformite sont supprimes.
        // Le score qualite agent retombe sur taux_performance uniquement.
        $scores = Action::query()
            ->forResponsable((int) $user->id)
            ->whereNotNull('taux_performance')
            ->latest('updated_at')
            ->limit(50)
            ->get(['taux_performance'])
            ->map(fn (Action $action): ?float => $action->taux_performance !== null
                ? (float) $action->taux_performance
                : null)
            ->filter(fn (?float $score): bool => $score !== null)
            ->values();

        if ($scores->isEmpty()) {
            return 100.0;
        }

        return round($this->bound((float) $scores->avg()), 1);
    }

    /**
     * @param Collection<int, array<string, mixed>> $openTasks
     */
    private function criticalityScore(Collection $openTasks): float
    {
        if ($openTasks->isEmpty()) {
            return 100.0;
        }

        $weights = [
            'normale' => 1.0,
            'importante' => 2.0,
            'critique' => 3.0,
        ];

        $totalWeight = 0.0;
        $lostWeight = 0.0;

        foreach ($openTasks as $task) {
            $taskWeight = $weights[(string) ($task['criticality'] ?? 'normale')] ?? 1.0;
            $totalWeight += $taskWeight;

            if ((bool) ($task['is_overdue'] ?? false)) {
                $lostWeight += $taskWeight;
            } elseif (($task['remaining_minutes'] ?? null) !== null && (int) $task['remaining_minutes'] <= 240) {
                $lostWeight += $taskWeight * 0.25;
            }
        }

        if ($totalWeight <= 0.0) {
            return 100.0;
        }

        return round($this->bound(100 - (($lostWeight / $totalWeight) * 100)), 1);
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
