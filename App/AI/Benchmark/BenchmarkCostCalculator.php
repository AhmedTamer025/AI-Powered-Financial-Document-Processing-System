<?php

namespace App\AI\Benchmark;

class BenchmarkCostCalculator
{
    public function calculate(
        string $model,
        int|float $inputTokens = 0,
        int|float $outputTokens = 0,
        int|float $reasoningTokens = 0,
    ): array {
        $inputTokens = max(0, (int) $inputTokens);
        $outputTokens = max(0, (int) $outputTokens);
        $reasoningTokens = max(0, (int) $reasoningTokens);

        $pricing = config(
            "ai.benchmark.models.{$model}.pricing",
            []
        );

        $inputPrice = (float) ($pricing['input'] ?? 0);
        $outputPrice = (float) ($pricing['output'] ?? 0);
        $reasoningPrice = (float) (
            $pricing['reasoning']
            ?? $outputPrice
        );

        $inputCost =
            ($inputTokens / 1_000_000)
            * $inputPrice;

        $outputCost =
            ($outputTokens / 1_000_000)
            * $outputPrice;

        $reasoningCost =
            ($reasoningTokens / 1_000_000)
            * $reasoningPrice;

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,

            'total_tokens' =>
                $inputTokens
                + $outputTokens
                + $reasoningTokens,

            'input_cost' => round($inputCost, 8),
            'output_cost' => round($outputCost, 8),
            'reasoning_cost' => round($reasoningCost, 8),

            'total_cost' => round(
                $inputCost
                + $outputCost
                + $reasoningCost,
                8
            ),
        ];
    }
}
