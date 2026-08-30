<?php

namespace App\AI\Benchmark;

use App\AI\Agents\BankStatementNormalizationAgent;
use App\AI\Agents\FinancialStatementNormalizationAgent;
use App\AI\Builders\BankStatementPromptBuilder;
use App\AI\Builders\ExcelContentBuilder;
use App\AI\Builders\FinancialStatementPromptBuilder;
use App\AI\Builders\OcrContentBuilder;
use App\AI\Chunking\DocumentChunker;
use App\AI\Services\FileDispatcherService;
use App\Models\BenchmarkDataset;
use App\Models\BenchmarkDatasetFile;
use App\Models\BenchmarkFieldResult;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Throwable;

class ModelBenchmarkRunner
{
    public function __construct(
        protected BenchmarkDatasetLoader $loader,
        protected BenchmarkEvaluator $evaluator,
        protected FileDispatcherService $dispatcher,
        protected DocumentChunker $chunker,
        protected BenchmarkCostCalculator $costCalculator,
    ) {}

    public function run(
        string $datasetId,
        array $models = ['mistral', 'gemini']
    ): array {
        $dataset = $this->loader->loadDatabaseDataset(
            $datasetId,
            'v1'
        );

        $results = [];

        foreach ($dataset->files as $file) {
            foreach ($models as $model) {
                $results[] = $this->runModel(
                    $dataset,
                    $file,
                    $model
                );
            }
        }

        return $results;
    }

    protected function runModel(
        BenchmarkDataset $dataset,
        BenchmarkDatasetFile $file,
        string $model
    ): BenchmarkResult {
        $start = microtime(true);

        $run = BenchmarkRun::create([
            'benchmark_dataset_id' => $dataset->id,
            'model' => $model,
            'provider' => $model === 'gemini'
                ? 'google'
                : 'mistral',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Execute
            |--------------------------------------------------------------------------
            */

            $data = $this->execute(
                $file,
                $model
            );

            /*
            |--------------------------------------------------------------------------
            | Evaluation
            |--------------------------------------------------------------------------
            */

            $evaluation = $this->evaluator->evaluate(
                $file->ground_truth ?? [],
                $data['prediction']
            );

            /*
            |--------------------------------------------------------------------------
            | Usage
            |--------------------------------------------------------------------------
            */

            $usage = $data['usage'];

            $time = $this->time($start);

            /*
            |--------------------------------------------------------------------------
            | Update Run + Save Result
            |--------------------------------------------------------------------------
            */

            return DB::transaction(function () use (
                $run,
                $file,
                $data,
                $evaluation,
                $usage,
                $time
            ) {
                $run->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'processing_time_ms' => $time,

                    'total_tokens' =>
                        $usage['total_tokens'],

                    'total_cost' =>
                        $usage['cost'],
                ]);

                $result = BenchmarkResult::create([
                    'benchmark_run_id' =>
                        $run->id,

                    'benchmark_dataset_file_id' =>
                        $file->id,

                    'status' => 'completed',

                    'prediction' =>
                        $data['prediction'],

                    'evaluation' =>
                        $evaluation,

                    'accuracy' =>
                        $evaluation['accuracy'] ?? null,

                    'correct_fields' =>
                        $evaluation['correct_fields'] ?? 0,

                    'incorrect_fields' =>
                        $evaluation['incorrect_fields'] ?? 0,

                    'missing_fields' =>
                        $evaluation['missing_fields'] ?? 0,

                    'extra_fields' =>
                        $evaluation['extra_fields'] ?? 0,

                    'processing_time_ms' =>
                        $time,

                    /*
                    |--------------------------------------------------------------------------
                    | Extraction
                    |--------------------------------------------------------------------------
                    */

                    'extraction_input_tokens' =>
                        $usage['extraction']['input_tokens'],

                    'extraction_output_tokens' =>
                        $usage['extraction']['output_tokens'],

                    'extraction_reasoning_tokens' =>
                        $usage['extraction']['reasoning_tokens'],

                    'extraction_total_tokens' =>
                        $usage['extraction']['total_tokens'],

                    'extraction_cost' =>
                        $usage['extraction']['cost'],

                    /*
                    |--------------------------------------------------------------------------
                    | Normalization
                    |--------------------------------------------------------------------------
                    */

                    'normalization_input_tokens' =>
                        $usage['normalization']['input_tokens'],

                    'normalization_output_tokens' =>
                        $usage['normalization']['output_tokens'],

                    'normalization_reasoning_tokens' =>
                        $usage['normalization']['reasoning_tokens'],

                    'normalization_total_tokens' =>
                        $usage['normalization']['total_tokens'],

                    'normalization_cost' =>
                        $usage['normalization']['cost'],

                    /*
                    |--------------------------------------------------------------------------
                    | Total
                    |--------------------------------------------------------------------------
                    */

                    'input_tokens' =>
                        $usage['input_tokens'],

                    'output_tokens' =>
                        $usage['output_tokens'],

                    'total_tokens' =>
                        $usage['total_tokens'],

                    'cost' =>
                        $usage['cost'],
                ]);

                /*
                |--------------------------------------------------------------------------
                | Field Results
                |--------------------------------------------------------------------------
                */

                foreach (
                    $evaluation['field_results'] ?? []
                    as $field
                ) {
                    BenchmarkFieldResult::create([
                        'benchmark_result_id' =>
                            $result->id,

                        'field' =>
                            $field['field'],

                        'status' =>
                            $field['status'],

                        'expected_value' =>
                            $this->json(
                                $field['expected_value'] ?? null
                            ),

                        'actual_value' =>
                            $this->json(
                                $field['actual_value'] ?? null
                            ),
                    ]);
                }

                return $result;
            });

        } catch (Throwable $e) {

            $time = $this->time($start);

            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'processing_time_ms' => $time,
                'error' => $e->getMessage(),
            ]);

            return BenchmarkResult::create([
                'benchmark_run_id' =>
                    $run->id,

                'benchmark_dataset_file_id' =>
                    $file->id,

                'status' => 'failed',

                'processing_time_ms' =>
                    $time,

                'error' =>
                    $e->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Execute Benchmark
    |--------------------------------------------------------------------------
    */

    protected function execute(
        BenchmarkDatasetFile $file,
        string $model
    ): array {
        if (!in_array(
            $model,
            ['mistral', 'gemini'],
            true
        )) {
            throw new RuntimeException(
                "Unsupported model [$model]."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Extraction
        |--------------------------------------------------------------------------
        */

        $document = $this->dispatcher->extract(
            $file->path
        );

        $content = match ($document->extension) {

            'xlsx',
            'xls',
            'csv',
            'ods' =>
                ExcelContentBuilder::build($document),

            default =>
                OcrContentBuilder::build($document),
        };

        $content =
            \App\AI\Services\Utf8Sanitizer::clean(
                $content
            );

        $chunks = $this->chunker->chunk(
            $document,
            $content
        );

        if (!$chunks) {
            throw new RuntimeException(
                'Benchmark document produced no chunks.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Agent
        |--------------------------------------------------------------------------
        */

        $agent = $this->agent(
            $model,
            $file->document_type
        );

        $responses = [];

        $extractionUsage =
            $this->emptyUsage();

        $normalizationUsage =
            $this->emptyUsage();

        /*
        |--------------------------------------------------------------------------
        | Process Chunks
        |--------------------------------------------------------------------------
        */

        foreach ($chunks as $chunk) {

            $prompt = match (
                $file->document_type
            ) {

                'bank_statement' =>
                    BankStatementPromptBuilder::build(
                        $document,
                        $chunk
                    ),

                'financial_statement' =>
                    FinancialStatementPromptBuilder::build(
                        $document,
                        $chunk
                    ),

                default =>
                    throw new RuntimeException(
                        "Unsupported document type " .
                        "[{$file->document_type}]."
                    ),
            };

            $prompt =
                \App\AI\Services\Utf8Sanitizer::clean(
                    $prompt
                );

            /*
            |--------------------------------------------------------------------------
            | AI Request
            |--------------------------------------------------------------------------
            */

            $response = $this->retry(
                $agent,
                $prompt
            );

            /*
            |--------------------------------------------------------------------------
            | Decode
            |--------------------------------------------------------------------------
            */

            $decoded = $this->decode(
                $response
            );

            if (!is_array($decoded)) {
                throw new RuntimeException(
                    "Invalid response returned by {$model}."
                );
            }

            $responses[] = $decoded;

            /*
            |--------------------------------------------------------------------------
            | Usage
            |--------------------------------------------------------------------------
            */

            $usage = $this->usage(
                $response,
                $model
            );

            $normalizationUsage =
                $this->mergeUsage(
                    $normalizationUsage,
                    $usage
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Merge Responses
        |--------------------------------------------------------------------------
        */

        $prediction =
            $this->merge($responses);

        /*
        |--------------------------------------------------------------------------
        | Bank Statement Adapter
        |--------------------------------------------------------------------------
        */

        if (
            $file->document_type === 'bank_statement'
            &&
            !isset($prediction['bank_statement'])
        ) {
            $prediction =
                $this->normalizeBank(
                    $prediction
                );

        } elseif (
            $file->document_type === 'bank_statement'
        ) {
            $prediction =
                app(
                    BankStatementBenchmarkAdapter::class
                )->transform(
                    $prediction
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Cost
        |--------------------------------------------------------------------------
        |
        | Current benchmark architecture treats the AI request here as
        | normalization usage. If extraction itself is an AI request,
        | its usage should be returned separately by the extraction adapter.
        |
        */

        $extractionCost =
            $this->calculateUsageCost(
                $model,
                $extractionUsage
            );

        $normalizationCost =
            $this->calculateUsageCost(
                $model,
                $normalizationUsage
            );

        /*
        |--------------------------------------------------------------------------
        | Final Usage
        |--------------------------------------------------------------------------
        */

        $totalInputTokens =
            $extractionCost['input_tokens']
            + $normalizationCost['input_tokens'];

        $totalOutputTokens =
            $extractionCost['output_tokens']
            + $normalizationCost['output_tokens'];

        $totalReasoningTokens =
            $extractionCost['reasoning_tokens']
            + $normalizationCost['reasoning_tokens'];

        $totalTokens =
            $extractionCost['total_tokens']
            + $normalizationCost['total_tokens'];

        $totalCost =
            $extractionCost['total_cost']
            + $normalizationCost['total_cost'];

        return [
            'prediction' => $prediction,

            'usage' => [
                'extraction' =>
                    $extractionCost,

                'normalization' =>
                    $normalizationCost,

                'input_tokens' =>
                    $totalInputTokens,

                'output_tokens' =>
                    $totalOutputTokens,

                'reasoning_tokens' =>
                    $totalReasoningTokens,

                'total_tokens' =>
                    $totalTokens,

                'cost' =>
                    $totalCost,
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    */

    protected function agent(
        string $model,
        string $type
    ): object {
        return match ($model) {

            'mistral' => match ($type) {

                'bank_statement' =>
                    new BankStatementNormalizationAgent(),

                'financial_statement' =>
                    new FinancialStatementNormalizationAgent(),

                default =>
                    throw new RuntimeException(
                        "Unsupported type [$type]."
                    ),
            },

            'gemini' => match ($type) {

                'bank_statement' =>
                    new GeminiBankStatementAgent(),

                'financial_statement' =>
                    new GeminiFinancialStatementAgent(),

                default =>
                    throw new RuntimeException(
                        "Unsupported type [$type]."
                    ),
            },

            default =>
                throw new RuntimeException(
                    "Unsupported model [$model]."
                ),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    */

    protected function retry(
        object $agent,
        string $prompt
    ): mixed {
        for (
            $attempt = 1;
            $attempt <= 5;
            $attempt++
        ) {
            try {
                return $agent->prompt(
                    $prompt
                );

            } catch (Throwable $e) {

                if ($attempt === 5) {
                    throw $e;
                }

                $wait = 2 ** $attempt;

                if (
                    $e instanceof RateLimitedException
                ) {
                    $wait *= 2;
                }

                sleep(
                    max(1, $wait)
                );
            }
        }

        throw new RuntimeException(
            'AI benchmark request failed.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Decode
    |--------------------------------------------------------------------------
    */

    protected function decode(
        mixed $response
    ): ?array {

        if (is_array($response)) {
            return $response;
        }

        if (
            isset($response->structured)
        ) {
            $value =
                $response->structured;

            if (is_array($value)) {
                return $value;
            }

            if (
                is_object($value)
                &&
                method_exists(
                    $value,
                    'toArray'
                )
            ) {
                return $value->toArray();
            }
        }

        if (
            is_object($response)
            &&
            method_exists(
                $response,
                'toArray'
            )
        ) {
            $data =
                $response->toArray();

            return $data['structured']
                ?? $data;
        }

        $data =
            json_decode(
                (string) $response,
                true
            );

        if (json_last_error()) {
            throw new RuntimeException(
                'Invalid JSON: '
                . json_last_error_msg()
            );
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Usage
    |--------------------------------------------------------------------------
    */

    protected function usage(
        mixed $response,
        string $model
    ): array {
        $usage =
            $response->usage ?? null;

        if (
            !$usage
            &&
            isset($response->steps)
        ) {
            $result =
                $this->emptyUsage();

            foreach (
                $response->steps
                as $step
            ) {
                if (
                    isset($step->usage)
                ) {
                    $result =
                        $this->mergeUsage(
                            $result,
                            $this->usageData(
                                $step->usage
                            )
                        );
                }
            }

            return $result;
        }

        return $usage
            ? $this->usageData($usage)
            : $this->emptyUsage();
    }

    protected function usageData(
        mixed $usage
    ): array {

        $data = is_array($usage)
            ? $usage
            : (
                method_exists(
                    $usage,
                    'toArray'
                )
                    ? $usage->toArray()
                    : []
            );

        $input =
            $data['input_tokens']
            ?? $data['prompt_tokens']
            ?? $data['promptTokens']
            ?? $data['inputTokens']
            ?? null;

        $output =
            $data['output_tokens']
            ?? $data['completion_tokens']
            ?? $data['completionTokens']
            ?? $data['outputTokens']
            ?? null;

        $reasoning =
            $data['reasoning_tokens']
            ?? $data['reasoningTokens']
            ?? null;

        $input =
            $input !== null
                ? (int) $input
                : 0;

        $output =
            $output !== null
                ? (int) $output
                : 0;

        $reasoning =
            $reasoning !== null
                ? (int) $reasoning
                : 0;

        return [
            'input_tokens' =>
                $input,

            'output_tokens' =>
                $output,

            'reasoning_tokens' =>
                $reasoning,

            'total_tokens' =>
                $input
                + $output
                + $reasoning,
        ];
    }

    protected function calculateUsageCost(
        string $model,
        array $usage
    ): array {

        $input =
            (int) (
                $usage['input_tokens']
                ?? 0
            );

        $output =
            (int) (
                $usage['output_tokens']
                ?? 0
            );

        $reasoning =
            (int) (
                $usage['reasoning_tokens']
                ?? 0
            );

        return $this->costCalculator->calculate(
            $model,
            $input,
            $output,
            $reasoning
        );
    }

    protected function mergeUsage(
        array $a,
        array $b
    ): array {

        foreach (
            [
                'input_tokens',
                'output_tokens',
                'reasoning_tokens',
                'total_tokens',
            ] as $key
        ) {
            $a[$key] =
                ($a[$key] ?? 0)
                +
                ($b[$key] ?? 0);
        }

        return $a;
    }

    protected function emptyUsage(): array
    {
        return [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'total_tokens' => 0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Cost
    |--------------------------------------------------------------------------
    */

    protected function cost(
        string $model,
        ?int $input,
        ?int $output,
        ?int $reasoning
    ): array {
        return $this->costCalculator->calculate(
            $model,
            $input ?? 0,
            $output ?? 0,
            $reasoning ?? 0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bank Normalization
    |--------------------------------------------------------------------------
    */

    protected function normalizeBank(
        array $data
    ): array {

        $transactions = [];

        foreach (
            $data['transactions'] ?? []
            as $transaction
        ) {
            if (!is_array($transaction)) {
                continue;
            }

            $description = strtoupper(
                trim(
                    (string) (
                        $transaction['description']
                        ?? ''
                    )
                )
            );

            if (
                in_array(
                    $description,
                    [
                        'OPENING BALANCE',
                        'CLOSING BALANCE'
                    ],
                    true
                )
            ) {
                continue;
            }

            $transactions[] = [
                'date' =>
                    $this->date(
                        $transaction['date']
                        ?? $transaction['posting_date']
                        ?? null
                    ),

                'description' =>
                    $transaction['description']
                    ?? null,

                'debit' =>
                    $this->number(
                        $transaction['debit']
                        ?? null,
                        true
                    ),

                'credit' =>
                    $this->number(
                        $transaction['credit']
                        ?? null,
                        true
                    ),

                'balance' =>
                    $transaction['balance']
                    ?? null,
            ];
        }

        return [
            'document_type' =>
                $data['document_type']
                ?? 'bank_statement',

            'account' => [
                'account_name' =>
                    $data['account_name']
                    ?? null,

                'currency' =>
                    $data['currency']
                    ?? null,

                'iban' =>
                    $data['iban']
                    ?? null,

                'account_number' =>
                    $data['account_number']
                    ?? null,

                'customer_id' =>
                    $data['customer_id']
                    ?? null,
            ],

            'reporting_period' => [
                'start_date' =>
                    $this->date(
                        $data['statement_from']
                        ?? $data['start_date']
                        ?? null
                    ),

                'end_date' =>
                    $this->date(
                        $data['statement_to']
                        ?? $data['end_date']
                        ?? null
                    ),
            ],

            'opening_balance' => [
                'value' =>
                    $this->number(
                        $data['opening_balance']
                        ?? null
                    ),
            ],

            'transactions' =>
                $transactions,

            'closing_balance' => [
                'value' =>
                    $this->number(
                        $data['closing_balance']
                        ?? null
                    ),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Merge
    |--------------------------------------------------------------------------
    */

    protected function merge(
        array $responses
    ): array {

        $result = [];

        foreach ($responses as $response) {
            $result =
                $this->mergeRecursive(
                    $result,
                    $response
                );
        }

        return $result;
    }

    protected function mergeRecursive(
        array $base,
        array $incoming
    ): array {

        foreach (
            $incoming
            as $key => $value
        ) {

            if (
                !array_key_exists(
                    $key,
                    $base
                )
            ) {
                $base[$key] = $value;

            } elseif (
                $key === 'transactions'
            ) {
                $base[$key] =
                    array_merge(
                        $base[$key] ?? [],
                        $value ?? []
                    );

            } elseif (
                is_array($base[$key])
                &&
                is_array($value)
            ) {
                $base[$key] =
                    $this->mergeRecursive(
                        $base[$key],
                        $value
                    );

            } elseif (
                (
                    $base[$key] === null
                    ||
                    $base[$key] === ''
                )
                &&
                $value !== null
                &&
                $value !== ''
            ) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function date(
        mixed $value
    ): ?string {

        if (!$value) {
            return null;
        }

        $value =
            strtoupper(
                trim((string) $value)
            );

        foreach (
            [
                'Y-m-d',
                'd M Y',
                'd F Y',
                'd-M-Y',
                'd/m/Y',
                'd/m/y',
                'Y/m/d',
                'dMY',
            ] as $format
        ) {
            $date =
                \DateTime::createFromFormat(
                    $format,
                    $value
                );

            if (
                $date
                &&
                $date->format($format) === $value
            ) {
                return $date->format(
                    'Y-m-d'
                );
            }
        }

        return ($time = strtotime($value))
            ? date(
                'Y-m-d',
                $time
            )
            : null;
    }

    protected function number(
        mixed $value,
        bool $absolute = false
    ): mixed {

        if (
            $value === null
            ||
            $value === ''
        ) {
            return null;
        }

        $value =
            is_string($value)
                ? str_replace(
                    [',', ' '],
                    '',
                    trim($value)
                )
                : $value;

        if (!is_numeric($value)) {
            return $value;
        }

        $value = (float) $value;

        return $absolute
            ? abs($value)
            : $value;
    }

    protected function time(
        float $start
    ): int {
        return (int) round(
            (
                microtime(true)
                - $start
            ) * 1000
        );
    }

    protected function json(
        mixed $value
    ): mixed {

        if (
            is_scalar($value)
            ||
            $value === null
        ) {
            return $value;
        }

        if (
            $value instanceof \JsonSerializable
        ) {
            return $value->jsonSerialize();
        }

        return is_object($value)
            &&
            method_exists(
                $value,
                'toArray'
            )
            ? $value->toArray()
            : $value;
    }
}
