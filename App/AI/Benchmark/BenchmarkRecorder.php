<?php

namespace App\AI\Benchmark;

use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\AiResult;

class BenchmarkRecorder
{
    public function start(
        string $model,
        string $provider,
        ?int $datasetId = null
    ): BenchmarkRun {
        return BenchmarkRun::create([
            'benchmark_dataset_id' => $datasetId,
            'model' => $model,
            'provider' => $provider,
            'status' => 'running',
            'started_at' => now(),
            'total_tokens' => 0,
            'total_cost' => 0,
        ]);
    }

    public function recordStage(
        BenchmarkRun $run,
        ?AiResult $aiResult,
        string $stage,
        string $model,
        string $provider,
        array $usage,
        ?string $sourceType = null,
        ?int $processingTimeMs = null
    ): BenchmarkResult {
        $usage =
            BenchmarkUsage::normalize(
                $usage
            );

        $calculator =
            app(BenchmarkCostCalculator::class);

        $cost =
            $calculator->calculate(
                $model,
                $usage['input_tokens'],
                $usage['output_tokens'],
                $usage['reasoning_tokens']
            );

        $data = [
            'benchmark_run_id' =>
                $run->id,

            'ai_result_id' =>
                $aiResult?->id,

            'status' =>
                'completed',

            'stage' =>
                $stage,

            'model' =>
                $model,

            'provider' =>
                $provider,

            'source_type' =>
                $sourceType,

            'processing_time_ms' =>
                $processingTimeMs,

            'tokens_used' =>
                $cost['total_tokens'],

            'cost' =>
                $cost['total_cost'],

            'total_tokens' =>
                $cost['total_tokens'],

            'total_cost' =>
                $cost['total_cost'],
        ];

        if ($stage === 'extraction') {
            $data += [
                'extraction_input_tokens' =>
                    $usage['input_tokens'],

                'extraction_output_tokens' =>
                    $usage['output_tokens'],

                'extraction_reasoning_tokens' =>
                    $usage['reasoning_tokens'],

                'extraction_total_tokens' =>
                    $cost['total_tokens'],

                'extraction_cost' =>
                    $cost['total_cost'],
            ];
        }

        if ($stage === 'normalization') {
            $data += [
                'normalization_input_tokens' =>
                    $usage['input_tokens'],

                'normalization_output_tokens' =>
                    $usage['output_tokens'],

                'normalization_reasoning_tokens' =>
                    $usage['reasoning_tokens'],

                'normalization_total_tokens' =>
                    $cost['total_tokens'],

                'normalization_cost' =>
                    $cost['total_cost'],
            ];
        }

        $result =
            BenchmarkResult::create($data);

        $this->refreshRun($run);

        return $result;
    }

    public function complete(
        BenchmarkRun $run,
        ?int $processingTimeMs = null
    ): void {
        $this->refreshRun($run);

        $run->update([
            'status' =>
                'completed',

            'processing_time_ms' =>
                $processingTimeMs,

            'completed_at' =>
                now(),
        ]);
    }

    public function fail(
        BenchmarkRun $run,
        string $error,
        ?int $processingTimeMs = null
    ): void {
        $this->refreshRun($run);

        $run->update([
            'status' =>
                'failed',

            'error' =>
                $error,

            'processing_time_ms' =>
                $processingTimeMs,

            'completed_at' =>
                now(),
        ]);
    }

    private function refreshRun(
        BenchmarkRun $run
    ): void {
        $results =
            $run->results()->get();

        $extractionTokens = 0;
        $extractionCost = 0;

        $normalizationTokens = 0;
        $normalizationCost = 0;

        $totalTokens = 0;
        $totalCost = 0;

        foreach ($results as $result) {
            $extractionTokens +=
                (int) $result->extraction_total_tokens;

            $extractionCost +=
                (float) $result->extraction_cost;

            $normalizationTokens +=
                (int) $result->normalization_total_tokens;

            $normalizationCost +=
                (float) $result->normalization_cost;

            $totalTokens +=
                (int) $result->total_tokens;

            $totalCost +=
                (float) $result->total_cost;
        }

        $run->update([
            'extraction_tokens' =>
                $extractionTokens,

            'extraction_cost' =>
                round($extractionCost, 8),

            'normalization_tokens' =>
                $normalizationTokens,

            'normalization_cost' =>
                round($normalizationCost, 8),

            'total_tokens' =>
                $totalTokens,

            'total_cost' =>
                round($totalCost, 8),
        ]);
    }
}
