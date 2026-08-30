<?php

namespace App\AI\Benchmark;

class BenchmarkCalculator
{
    /**
     * Calculate the cost of an AI operation.
     *
     * Supports:
     * - Token based pricing
     * - Page based pricing
     *
     * Pricing is loaded dynamically from config/ai_pricing.php
     */
    public function calculate(
        string $model,
        mixed $usage
    ): ?float {
        $pricing = $this->pricingFor($model);

        $type = $pricing['type'] ?? 'token';

        /*
        |--------------------------------------------------------------------------
        | Page Based Pricing
        |--------------------------------------------------------------------------
        */

        if ($type === 'page') {
            $pages = $this->usageValue($usage, [
                'pages',
                'page',
                'page_count',
                'pageCount',
            ]);

            if ($pages === null) {
                return null;
            }

            $pricePerPage = (float) ($pricing['page'] ?? 0);

            return round($pages * $pricePerPage, 8);
        }

        /*
        |--------------------------------------------------------------------------
        | Token Based Pricing
        |--------------------------------------------------------------------------
        */

        $inputCost =
            (($this->usageValue($usage, ['inputTokens', 'input_tokens']) ?? 0) / 1_000_000)
            * (float) ($pricing['input'] ?? 0);

        $outputCost =
            (($this->usageValue($usage, ['outputTokens', 'output_tokens']) ?? 0) / 1_000_000)
            * (float) ($pricing['output'] ?? 0);

        $reasoningCost =
            (($this->usageValue($usage, ['reasoningTokens', 'reasoning_tokens']) ?? 0) / 1_000_000)
            * (float) ($pricing['reasoning'] ?? $pricing['output'] ?? 0);

        return round(
            $inputCost + $outputCost + $reasoningCost,
            8
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function pricingFor(string $model): array
    {
        $config = config("ai_pricing.models.{$model}");

        if (is_array($config) && isset($config['pricing']) && is_array($config['pricing'])) {
            return $config['pricing'];
        }

        if (is_array($config)) {
            return $config;
        }

        $fallback = config("ai.benchmark.models.{$model}.pricing");

        if (is_array($fallback)) {
            return $fallback + ['type' => 'token'];
        }

        $default = config('ai_pricing.default');

        return is_array($default) ? $default : [];
    }

    /**
     * @param  list<string>  $keys
     */
    private function usageValue(mixed $usage, array $keys): ?float
    {
        if ($usage instanceof BenchmarkUsage) {
            foreach (['pages', 'page', 'inputTokens', 'outputTokens', 'reasoningTokens'] as $property) {
                if (! in_array($property, $keys, true)) {
                    continue;
                }

                $value = $usage->{$property} ?? null;

                if (is_numeric($value)) {
                    return (float) $value;
                }
            }

            $usage = [
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'pages' => $usage->pages,
                'page' => $usage->page,
            ];
        }

        if (is_object($usage) && method_exists($usage, 'toArray')) {
            $usage = $usage->toArray();
        }

        if (is_object($usage)) {
            foreach ($keys as $key) {
                if (isset($usage->{$key}) && is_numeric($usage->{$key})) {
                    return (float) $usage->{$key};
                }
            }

            return null;
        }

        if (! is_array($usage)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $usage) && is_numeric($usage[$key])) {
                return (float) $usage[$key];
            }
        }

        return null;
    }
}
