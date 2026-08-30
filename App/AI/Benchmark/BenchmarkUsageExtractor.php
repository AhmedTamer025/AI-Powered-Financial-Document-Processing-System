<?php

namespace App\AI\Benchmark;

class BenchmarkUsageExtractor
{
    public function extract(array $usage): BenchmarkUsage
    {
        $input = $this->find(
            $usage,
            [
                'input_tokens',
                'prompt_tokens',
                'promptTokens',
                'inputTokens',
            ]
        );

        $output = $this->find(
            $usage,
            [
                'output_tokens',
                'completion_tokens',
                'completionTokens',
                'outputTokens',
            ]
        );

        $reasoning = $this->find(
            $usage,
            [
                'reasoning_tokens',
                'reasoningTokens',
            ]
        );

        $total = $this->find(
            $usage,
            [
                'total_tokens',
                'totalTokens',
            ]
        );

        $pages = $this->find(
            $usage,
            [
                'pages',
                'page_count',
                'pageCount',
                'number_of_pages',
                'numberOfPages',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Calculate total tokens if provider did not return it
        |--------------------------------------------------------------------------
        */

        if (
            $total === null &&
            ($input !== null || $output !== null || $reasoning !== null)
        ) {
            $total =
                ($input ?? 0)
                + ($output ?? 0)
                + ($reasoning ?? 0);
        }

        return new BenchmarkUsage(
            inputTokens: $input,
            outputTokens: $output,
            reasoningTokens: $reasoning,
            totalTokens: $total,
            pages: $pages,
        );
    }

    private function find(
        array $data,
        array $keys
    ): ?int {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $result = $this->find($value, $keys);

                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }
}
