<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiEmbeddingService
{
    /**
     * @return list<float>
     */
    public function embed(string $text, ?int $dimensions = null): array
    {
        $dimensions ??= max(8, (int) config('ai_training.pta.embedding_dimensions', 64));
        $vector = array_fill(0, $dimensions, 0.0);

        foreach ($this->tokens($text) as $token) {
            $hash = crc32($token);
            $index = abs($hash) % $dimensions;
            $vector[$index] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $value): float => round($value / $norm, 6), $vector);
    }

    /**
     * @param  list<float>|null  $left
     * @param  list<float>|null  $right
     */
    public function cosine(?array $left, ?array $right): float
    {
        if ($left === null || $right === null || $left === [] || $right === []) {
            return 0.0;
        }

        $limit = min(count($left), count($right));
        $dot = 0.0;
        for ($index = 0; $index < $limit; $index++) {
            $dot += ((float) $left[$index]) * ((float) $right[$index]);
        }

        return round($dot, 6);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = strtolower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        return array_values(array_filter(explode(' ', $text), static fn (string $token): bool => strlen($token) > 2));
    }
}
