<?php

namespace App\AI\Benchmark;

class BenchmarkUsage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $reasoningTokens = null,
        public ?int $totalTokens = null,
        public ?int $pages = null,
        public ?int $page = null,
    ) {
        $this->pages = $this->pages ?? $this->page;
        $this->page = $this->page ?? $this->pages;
    }

    public static function normalize(mixed $usage): array
    {
        if (is_object($usage) && method_exists($usage, 'toArray')) {
            $usage = $usage->toArray();
        }

        if (!is_array($usage)) {
            return self::empty();
        }

        $inputTokens = self::value(
            $usage,
            'input_tokens',
            'prompt_tokens',
            'promptTokens'
        );

        $outputTokens = self::value(
            $usage,
            'output_tokens',
            'completion_tokens',
            'completionTokens'
        );

        $reasoningTokens = self::value(
            $usage,
            'reasoning_tokens',
            'reasoningTokens'
        );

        $totalTokens = self::value(
            $usage,
            'total_tokens',
            'totalTokens'
        );

        if ($totalTokens === 0) {
            $totalTokens =
                $inputTokens
                + $outputTokens
                + $reasoningTokens;
        }

        return [
            'input_tokens' => max(0, (int) $inputTokens),
            'output_tokens' => max(0, (int) $outputTokens),
            'reasoning_tokens' => max(0, (int) $reasoningTokens),
            'total_tokens' => max(0, (int) $totalTokens),
        ];
    }

    public static function merge(
        array $base,
        array $incoming
    ): array {
        $base = self::normalize($base);
        $incoming = self::normalize($incoming);

        return [
            'input_tokens' =>
                $base['input_tokens']
                + $incoming['input_tokens'],

            'output_tokens' =>
                $base['output_tokens']
                + $incoming['output_tokens'],

            'reasoning_tokens' =>
                $base['reasoning_tokens']
                + $incoming['reasoning_tokens'],

            'total_tokens' =>
                $base['total_tokens']
                + $incoming['total_tokens'],
        ];
    }

    private static function value(
        array $usage,
        string ...$keys
    ): int {
        foreach ($keys as $key) {
            if (array_key_exists($key, $usage)) {
                return (int) $usage[$key];
            }
        }

        foreach ($usage as $value) {
            if (is_array($value)) {
                $nested = self::value($value, ...$keys);

                if ($nested > 0) {
                    return $nested;
                }
            }
        }

        return 0;
    }

    private static function empty(): array
    {
        return [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'total_tokens' => 0,
        ];
    }
}
