<?php

namespace App\Services\OpenAi;

use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

class OpenAiUsageBillingService
{
    /**
     * @return array{input:float,output:float,total:float}
     */
    public function estimateCost(int $inputTokens, int $outputTokens): array
    {
        $input = ($inputTokens / 1_000_000) * $this->inputUnitCost();
        $output = ($outputTokens / 1_000_000) * $this->outputUnitCost();

        return [
            'input' => round($input, 6),
            'output' => round($output, 6),
            'total' => round($input + $output, 6),
        ];
    }

    public function ensureMonthlyBudgetAllows(float $estimatedCost): void
    {
        $budget = $this->monthlyBudgetUsd();
        if ($budget <= 0) {
            return;
        }

        if (($this->currentMonthTotalUsd() + $estimatedCost) > $budget) {
            throw new RuntimeException('Budget mensuel IA depasse ou insuffisant pour lancer cette operation.');
        }
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function record(
        ?User $user,
        string $module,
        string $operationType,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?string $requestId = null,
        array $metadata = []
    ): AiUsageLog {
        $cost = $this->estimateCost($inputTokens, $outputTokens);

        return AiUsageLog::query()->create([
            'user_id' => $user?->id,
            'module' => $module,
            'operation_type' => $operationType,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'input_cost_usd' => $cost['input'],
            'output_cost_usd' => $cost['output'],
            'total_cost_usd' => $cost['total'],
            'request_id' => $requestId,
            'metadata' => $metadata,
        ]);
    }

    public function currentMonthTotalUsd(?Carbon $now = null): float
    {
        $now ??= now();

        return (float) AiUsageLog::query()
            ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('total_cost_usd');
    }

    public function monthlyBudgetUsd(): float
    {
        return max(0.0, (float) config('services.openai_responses.monthly_budget_usd', 20));
    }

    private function inputUnitCost(): float
    {
        return max(0.0, (float) config('services.openai_responses.input_cost_per_1m_tokens', 0));
    }

    private function outputUnitCost(): float
    {
        return max(0.0, (float) config('services.openai_responses.output_cost_per_1m_tokens', 0));
    }
}
