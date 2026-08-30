<?php

namespace App\Jobs;

use App\AI\Services\DocumentClassifierService;
use App\AI\Services\FileDispatcherService;
use App\AI\Services\Financial\SaveBankStatementService;
use App\AI\Services\Financial\SaveFinancialStatementService;
use App\AI\Services\GeminDocumentNormalizationService;
use App\AI\Services\AiResultArchiveService;
use App\AI\Services\SourceNormalizationService;

use App\Models\AiResult;
use App\Models\BankStatement;
use App\Models\BenchmarkResult;
use App\Models\FinancialStatement;
use App\Models\Business;

use App\Enums\DocumentType;
use App\Enums\DocumentStatus;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Throwable;
use Laravel\Ai\Exceptions\RateLimitedException;

class NormalizeUploadedDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    public array $backoff = [
        120,
        300,
        600,
    ];

    public function __construct(
        public string $businessId,
        public string $path,
        public string $originalName,
        public string $batchReference
    ) {}

    public function handle(
        FileDispatcherService $dispatcher,
        DocumentClassifierService $classifier,
        GeminDocumentNormalizationService $normalizer,
        SaveBankStatementService $bankService,
        SaveFinancialStatementService $financialService,
        AiResultArchiveService $archiver,
        SourceNormalizationService $sourceNormalizer
    ): void {

        set_time_limit(0);
        ini_set('max_execution_time', '0');

        Log::info('PHP LIMIT AT JOB START', [
            'max_execution_time' => ini_get('max_execution_time'),
        ]);

        $startTime = microtime(true);

        $aiResult = null;

        try {

            /*
            |--------------------------------------------------------------------------
            | Resolve Business
            |--------------------------------------------------------------------------
            */

            $business = $this->resolveBusiness();

            if (!$business) {
                throw new \RuntimeException(
                    'Business not found. Received business identifier: "'
                    . $this->businessId
                    . '".'
                );
            }

            Log::info('Business resolved for document normalization', [
                'provided_business_id' => $this->businessId,
                'resolved_business_id' => $business->id,
                'business_name' => $business->name,
            ]);

            /*
            |--------------------------------------------------------------------------
            | File Information
            |--------------------------------------------------------------------------
            */

            $absolutePath = Storage::disk('local')->path($this->path);

            $fileInfo = [

                'original_file_name' => $this->originalName,

                'stored_file_name' => basename($this->path),

                'stored_path' => $this->path,

                'extension' => pathinfo(
                    $this->path,
                    PATHINFO_EXTENSION
                ),

                'mime_type' => mime_content_type(
                    $absolutePath
                ),

                'size' => Storage::size(
                    $this->path
                ),
            ];

            /*
            |--------------------------------------------------------------------------
            | Classification
            |--------------------------------------------------------------------------
            */

            $classification = $classifier->classify(
                $absolutePath,
                (string) $business->id
            );

            /*
            |--------------------------------------------------------------------------
            | Extraction
            |--------------------------------------------------------------------------
            */

            $extractionStartTime = microtime(true);

            $document = $dispatcher->extract(
                $absolutePath
            );

            $extractionProcessingTimeMs = (int) (
                (microtime(true) - $extractionStartTime) * 1000
            );

            /*
            |--------------------------------------------------------------------------
            | Create AI Result
            |--------------------------------------------------------------------------
            */

            $aiResult = AiResult::create([

                'provider' => config(
                    'ai.extraction.provider',
                    'mistral'
                ),

                'model' => $this->extractionModel(),

                'document_type' => DocumentType::Unsupported,

                'status' => DocumentStatus::Extracting,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Raw Extraction
            |--------------------------------------------------------------------------
            */

            $rawExtractionPath = $archiver->store(
                $aiResult->id,
                'raw_extraction',
                [

                    'file_name' => $document->fileName,

                    'plain_text' => $document->plainText,

                    'sections' => $document->sections,

                    'tables' => $document->tables,

                    'metadata' => $document->metadata,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Extraction Usage
            |--------------------------------------------------------------------------
            */

            $rawExtractionUsage = $dispatcher->lastUsage();

            $extractionUsage = $this->normalizeUsage(
                $rawExtractionUsage
            );

            /*
            |--------------------------------------------------------------------------
            | Extraction Model / Provider
            |--------------------------------------------------------------------------
            */

            $extractionModel =
                $document->metadata['model']
                ?? $aiResult->model;

            $extractionProvider =
                $document->metadata['provider']
                ?? $aiResult->provider;

            /*
            |--------------------------------------------------------------------------
            | Extraction Page Count
            |--------------------------------------------------------------------------
            |
            | Needed for page-based OCR pricing.
            |
            */

            $extractionPages =
                $this->extractPageCount(
                    $document->metadata ?? []
                );

            /*
            |--------------------------------------------------------------------------
            | Extraction Cost
            |--------------------------------------------------------------------------
            |
            | Supports:
            |
            | 1. Token-based pricing
            | 2. Page-based OCR pricing
            |
            */

            $extractionCost = $this->calculateCost(
                $extractionModel,
                $extractionUsage,
                array_merge(
                    $document->metadata ?? [],
                    [
                        'pages' => $extractionPages,
                    ]
                )
            );

            $extractionUsage['cost'] =
                $extractionCost;

            /*
            |--------------------------------------------------------------------------
            | Log Extraction Benchmark Usage
            |--------------------------------------------------------------------------
            */

            Log::info('Extraction benchmark usage', [

                'file' =>
                    $this->originalName,

                'model' =>
                    $extractionModel,

                'provider' =>
                    $extractionProvider,

                'pages' =>
                    $extractionPages,

                'input_tokens' =>
                    $extractionUsage['input_tokens'],

                'output_tokens' =>
                    $extractionUsage['output_tokens'],

                'reasoning_tokens' =>
                    $extractionUsage['reasoning_tokens'],

                'total_tokens' =>
                    $extractionUsage['total_tokens'],

                'cost' =>
                    $extractionCost,

                'processing_time_ms' =>
                    $extractionProcessingTimeMs,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Extraction Benchmark Result
            |--------------------------------------------------------------------------
            */

            BenchmarkResult::create([

                'ai_result_id' =>
                    $aiResult->id,

                'stage' =>
                    'extraction',

                'model' =>
                    $extractionModel,

                'provider' =>
                    $extractionProvider,

                'source_type' =>
                    $document->metadata['mode']
                    ??
                    config(
                        'ai.extraction.mode',
                        'ocr'
                    ),

                'status' =>
                    'completed',

                /*
                |--------------------------------------------------------------------------
                | Extraction Tokens
                |--------------------------------------------------------------------------
                */

                'extraction_input_tokens' =>
                    $extractionUsage['input_tokens'],

                'extraction_output_tokens' =>
                    $extractionUsage['output_tokens'],

                'extraction_reasoning_tokens' =>
                    $extractionUsage['reasoning_tokens'],

                'extraction_total_tokens' =>
                    $extractionUsage['total_tokens'],

                /*
                |--------------------------------------------------------------------------
                | Extraction Cost
                |--------------------------------------------------------------------------
                */

                'extraction_cost' =>
                    $extractionCost,

                /*
                |--------------------------------------------------------------------------
                | Overall Values
                |--------------------------------------------------------------------------
                */

                'total_tokens' =>
                    $extractionUsage['total_tokens'],

                'total_cost' =>
                    $extractionCost,

                'processing_time_ms' =>
                    $extractionProcessingTimeMs,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update AI Result
            |--------------------------------------------------------------------------
            */

            $aiResult->update([

                'raw_extraction' => $rawExtractionPath,

                'status' => DocumentStatus::Extracted,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Classify Document
            |--------------------------------------------------------------------------
            */

            $documentTypeLabel = $classification->documentType;

            $aiResult->update([

                'document_type' =>
                    DocumentType::fromLabel(
                        $documentTypeLabel
                    ),
            ]);

            if ($documentTypeLabel === 'unsupported') {

                throw new \RuntimeException(
                    'The uploaded document is not a supported financial document.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Business Name
            |--------------------------------------------------------------------------
            */

            if (
                $business->name &&
                !$classification->businessNameMatch
            ) {

                throw new \RuntimeException(
                    'Document business name "'
                    . (
                        $classification->detectedBusinessName !== ''
                            ? $classification->detectedBusinessName
                            : 'not found'
                    )
                    . '" does not match "'
                    . $business->name
                    . '". '
                    . $classification->businessNameMatchReason
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Create Empty Business Record
            |--------------------------------------------------------------------------
            */

            if ($documentTypeLabel === 'bank_statement') {

                BankStatement::create([

                    'business_id' => $business->id,

                    'ai_result_id' => $aiResult->id,

                    ...$fileInfo,
                ]);
            }

            if ($documentTypeLabel === 'financial_statement') {

                FinancialStatement::create([

                    'business_id' => $business->id,

                    'ai_result_id' => $aiResult->id,

                    ...$fileInfo,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize With AI
            |--------------------------------------------------------------------------
            */

            $aiResult->update([

                'status' => DocumentStatus::Normalizing,
            ]);

            $normalizationStartTime = microtime(true);

            $normalized = $normalizer->normalize(
                $document,
                $documentTypeLabel
            );

            $normalizationProcessingTimeMs = (int) (
                (microtime(true) - $normalizationStartTime) * 1000
            );

            /*
            |--------------------------------------------------------------------------
            | Normalization Usage
            |--------------------------------------------------------------------------
            */

            $rawNormalizationUsage = $normalizer->lastUsage();

            $normalizationUsage = $this->normalizeUsage(
                $rawNormalizationUsage
            );

            /*
            |--------------------------------------------------------------------------
            | Normalization Model / Provider
            |--------------------------------------------------------------------------
            */

            $normalizationModel =
                $this->normalizationModel();

            $normalizationProvider =
                config(
                    'ai.extraction.provider',
                    'mistral'
                );

            /*
            |--------------------------------------------------------------------------
            | Normalization Cost
            |--------------------------------------------------------------------------
            */

            $normalizationCost = $this->calculateCost(
                $normalizationModel,
                $normalizationUsage,
                []
            );

            $normalizationUsage['cost'] =
                $normalizationCost;

            /*
            |--------------------------------------------------------------------------
            | Log Normalization Benchmark Usage
            |--------------------------------------------------------------------------
            */

            Log::info('Normalization benchmark usage', [

                'file' =>
                    $this->originalName,

                'model' =>
                    $normalizationModel,

                'provider' =>
                    $normalizationProvider,

                'input_tokens' =>
                    $normalizationUsage['input_tokens'],

                'output_tokens' =>
                    $normalizationUsage['output_tokens'],

                'reasoning_tokens' =>
                    $normalizationUsage['reasoning_tokens'],

                'total_tokens' =>
                    $normalizationUsage['total_tokens'],

                'cost' =>
                    $normalizationCost,

                'processing_time_ms' =>
                    $normalizationProcessingTimeMs,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Normalization Benchmark Result
            |--------------------------------------------------------------------------
            */

            BenchmarkResult::create([

                'ai_result_id' =>
                    $aiResult->id,

                'stage' =>
                    'normalization',

                'model' =>
                    $normalizationModel,

                'provider' =>
                    $normalizationProvider,

                'source_type' =>
                    null,

                'status' =>
                    'completed',

                /*
                |--------------------------------------------------------------------------
                | Normalization Tokens
                |--------------------------------------------------------------------------
                */

                'normalization_input_tokens' =>
                    $normalizationUsage['input_tokens'],

                'normalization_output_tokens' =>
                    $normalizationUsage['output_tokens'],

                'normalization_reasoning_tokens' =>
                    $normalizationUsage['reasoning_tokens'],

                'normalization_total_tokens' =>
                    $normalizationUsage['total_tokens'],

                'normalization_cost' =>
                    $normalizationCost,

                /*
                |--------------------------------------------------------------------------
                | Overall Values
                |--------------------------------------------------------------------------
                */

                'total_tokens' =>
                    $normalizationUsage['total_tokens'],

                'total_cost' =>
                    $normalizationCost,

                'processing_time_ms' =>
                    $normalizationProcessingTimeMs,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Normalized Array
            |--------------------------------------------------------------------------
            */

            $normalizedArray = [

                'document_type' =>
                    $normalized->documentType,

                'reporting_periods' =>
                $normalized->reportingPeriods,


                'bank_statement' =>
                    $normalized->bankStatement,

                'balance_sheet' =>
                    $normalized->balanceSheet,

                'income_statement' =>
                    $normalized->incomeStatement,

                'cash_flow_statement' =>
                    $normalized->cashFlowStatement,

                'equity_statement' =>
                    $normalized->equityStatement,

                'overall_confidence' =>
                    $normalized->overallConfidence,
            ];

            /*
            |--------------------------------------------------------------------------
            | Post-process Shapes
            |--------------------------------------------------------------------------
            */

            $normalizedArray = $this->ensureFinancialSources(
                $normalizedArray,
                $sourceNormalizer
            );

            $normalizedArray = $this->ensureBankStatement(
                $normalizedArray,
                $sourceNormalizer
            );

            /*
            |--------------------------------------------------------------------------
            | Save Normalized Result
            |--------------------------------------------------------------------------
            */

            $normalizedResultPath = $archiver->store(
                $aiResult->id,
                'normalized_result',
                $normalizedArray
            );

            /*
            |--------------------------------------------------------------------------
            | Update AI Result
            |--------------------------------------------------------------------------
            */

            $aiResult->update([

                'normalized_result' =>
                    $normalizedResultPath,

                'overall_confidence' =>
                    $normalized->overallConfidence,

                'warnings' =>
                    $normalized->warnings,

                'status' =>
                    DocumentStatus::Normalized,

                'processing_time_ms' =>
                    (int) (
                        (microtime(true) - $startTime) * 1000
                    ),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Structured Data
            |--------------------------------------------------------------------------
            */

            if ($documentTypeLabel === 'bank_statement') {

                $bankService->execute(
                    $normalizedArray,
                    $business->id,
                    $aiResult->id,
                    $fileInfo
                );
            }

            if ($documentTypeLabel === 'financial_statement') {

                $financialService->execute(
                    $normalizedArray,
                    $business->id,
                    $aiResult->id,
                    $fileInfo
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Completed
            |--------------------------------------------------------------------------
            */

            $aiResult->update([

                'status' =>
                    DocumentStatus::Completed,
            ]);

        } catch (RateLimitedException $e) {

            logger()->warning(
                'AI rate limited, releasing job',
                [
                    'file' => $this->originalName,
                ]);
                
            $base = max(1, method_exists($this, 'attempts') ? $this->attempts() : 1);
            $delay = min(180, max(60, 60 * $base));

            $normalizationUsage = $normalizer->lastUsage();

            BenchmarkResult::create([
                'ai_result_id' => $aiResult->id,
                'stage' => 'normalization',
                'model' => $this->normalizationModel(),
                'provider' => config('ai.extraction.provider', 'mistral'),
                'source_type' => null,
                'processing_time_ms' => null,
                'tokens_used' => $normalizationUsage['total_tokens'] ?? null,
                'cost' => $normalizationUsage['cost'] ?? null,
            ]);
            $this->release($delay);

            return;
            
        } catch (Throwable $e) {

            Log::error(
                'Document pipeline failed',
                [
                    'file' => $this->originalName,

                    'error' => $e->getMessage(),
                ]
            );

            if ($aiResult) {

                $aiResult->refresh();

                if (
                    $aiResult->status ===
                    DocumentStatus::Completed
                ) {
                    return;
                }

                $aiResult->update([

                    'status' =>
                        DocumentStatus::Failed,

                    'error_message' =>
                        $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Business
    |--------------------------------------------------------------------------
    */

    protected function resolveBusiness(): ?Business
    {
        $business = Business::query()
            ->whereKey($this->businessId)
            ->first();

        if ($business) {
            return $business;
        }

        return Business::query()
            ->where('name', $this->businessId)
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Queue Permanently Failed
    |--------------------------------------------------------------------------
    */

    public function failed(Throwable $exception): void
    {
        Log::error(
            'Normalize Job permanently failed',
            [
                'file' => $this->originalName,

                'error' => $exception->getMessage(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize Usage
    |--------------------------------------------------------------------------
    |
    | Converts provider/SDK usage structures into:
    |
    | input_tokens
    | output_tokens
    | reasoning_tokens
    | total_tokens
    | cost
    |
    */

    protected function normalizeUsage(mixed $usage): array
    {
        if ($usage === null) {
            return [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'total_tokens' => 0,
                'cost' => 0.0,
            ];
        }

        if (is_object($usage)) {

            if (method_exists($usage, 'toArray')) {
                $usage = $usage->toArray();
            } else {
                $usage = (array) $usage;
            }
        }

        if (!is_array($usage)) {
            return [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'total_tokens' => 0,
                'cost' => 0.0,
            ];
        }

        $inputTokens = $this->extractUsageValue(
            $usage,
            'input_tokens',
            'inputTokens',
            'prompt_tokens',
            'promptTokens'
        );

        $outputTokens = $this->extractUsageValue(
            $usage,
            'output_tokens',
            'outputTokens',
            'completion_tokens',
            'completionTokens'
        );

        $reasoningTokens = $this->extractUsageValue(
            $usage,
            'reasoning_tokens',
            'reasoningTokens'
        );

        $totalTokens = $this->extractUsageValue(
            $usage,
            'total_tokens',
            'totalTokens'
        );

        $inputTokens = is_numeric($inputTokens)
            ? (int) $inputTokens
            : 0;

        $outputTokens = is_numeric($outputTokens)
            ? (int) $outputTokens
            : 0;

        $reasoningTokens = is_numeric($reasoningTokens)
            ? (int) $reasoningTokens
            : 0;

        if (
            $totalTokens === null ||
            !is_numeric($totalTokens)
        ) {

            $totalTokens =
                $inputTokens
                + $outputTokens
                + $reasoningTokens;

        } else {

            $totalTokens = (int) $totalTokens;
        }

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'total_tokens' => $totalTokens,
            'cost' => 0.0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate Cost
    |--------------------------------------------------------------------------
    |
    | Reads pricing from config/ai_pricing.php.
    |
    | Token pricing:
    |
    | input     / 1,000,000 * input price
    | output    / 1,000,000 * output price
    | reasoning / 1,000,000 * reasoning price
    |
    | Page pricing:
    |
    | pages * page price
    |
    */

    protected function calculateCost(
        string $model,
        array $usage,
        array $metadata = []
    ): float {

        $modelConfig = config(
            "ai_pricing.models.{$model}"
        );

        if (!is_array($modelConfig)) {

            Log::warning(
                'AI pricing configuration not found',
                [
                    'model' => $model,
                ]
            );

            return 0.0;
        }

        $pricing =
            $modelConfig['pricing']
            ?? [];

        $pricingType =
            $pricing['type']
            ?? 'token';

        /*
        |--------------------------------------------------------------------------
        | Page-Based Pricing
        |--------------------------------------------------------------------------
        */

        if ($pricingType === 'page') {

            $pages = $this->extractPageCount(
                $metadata
            );

            if ($pages <= 0) {

                Log::warning(
                    'Unable to determine page count for page-based pricing',
                    [
                        'model' => $model,
                        'metadata' => $metadata,
                    ]
                );

                return 0.0;
            }

            $pagePrice = (float) (
                $pricing['page']
                ?? 0
            );

            return round(
                $pages * $pagePrice,
                8
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Token-Based Pricing
        |--------------------------------------------------------------------------
        */

        $inputTokens =
            (int) (
                $usage['input_tokens']
                ?? 0
            );

        $outputTokens =
            (int) (
                $usage['output_tokens']
                ?? 0
            );

        $reasoningTokens =
            (int) (
                $usage['reasoning_tokens']
                ?? 0
            );

        $inputPrice =
            (float) (
                $pricing['input']
                ?? 0
            );

        $outputPrice =
            (float) (
                $pricing['output']
                ?? 0
            );

        $reasoningPrice =
            (float) (
                $pricing['reasoning']
                ?? 0
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

        $totalCost =
            $inputCost
            + $outputCost
            + $reasoningCost;

        return round(
            $totalCost,
            8
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Page Count
    |--------------------------------------------------------------------------
    |
    | Mistral OCR is page-priced.
    |
    | Different extraction implementations may expose the page count under
    | different metadata keys, so we check the common possibilities.
    |
    */

    protected function extractPageCount(
        array $metadata
    ): int {

        $possibleKeys = [

            'pages',

            'page_count',

            'pageCount',

            'number_of_pages',

            'numberOfPages',

            'total_pages',

            'totalPages',
        ];

        foreach ($possibleKeys as $key) {

            if (
                array_key_exists(
                    $key,
                    $metadata
                )
                &&
                is_numeric(
                    $metadata[$key]
                )
            ) {

                return max(
                    0,
                    (int) $metadata[$key]
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Nested metadata
        |--------------------------------------------------------------------------
        */

        foreach ($metadata as $value) {

            if (
                is_array($value)
                ||
                is_object($value)
            ) {

                if (is_object($value)) {

                    if (
                        method_exists(
                            $value,
                            'toArray'
                        )
                    ) {

                        $value =
                            $value->toArray();

                    } else {

                        $value =
                            (array) $value;
                    }
                }

                $pages =
                    $this->extractPageCount(
                        $value
                    );

                if ($pages > 0) {
                    return $pages;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback: Physical PDF Page Count
        |--------------------------------------------------------------------------
        |
        | If metadata does not contain the page count, inspect the stored PDF.
        |
        */

        return $this->getPdfPageCount();
    }

    /*
    |--------------------------------------------------------------------------
    | Get PDF Page Count
    |--------------------------------------------------------------------------
    */

    protected function getPdfPageCount(): int
    {
        try {

            $absolutePath =
                Storage::disk('local')
                    ->path($this->path);

            if (
                !is_file($absolutePath)
            ) {
                return 0;
            }

            /*
            |--------------------------------------------------------------------------
            | Try pdfinfo first
            |--------------------------------------------------------------------------
            */

            $command =
                'pdfinfo '
                . escapeshellarg($absolutePath)
                . ' 2>&1';

            $output =
                shell_exec($command);

            if (
                is_string($output)
                &&
                preg_match(
                    '/^Pages:\s+(\d+)/mi',
                    $output,
                    $matches
                )
            ) {

                return max(
                    0,
                    (int) $matches[1]
                );
            }

        } catch (Throwable $e) {

            Log::warning(
                'Unable to determine PDF page count',
                [
                    'file' => $this->originalName,
                    'error' => $e->getMessage(),
                ]
            );
        }

        return 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Usage Value
    |--------------------------------------------------------------------------
    */

    protected function extractUsageValue(
        mixed $usage,
        string ...$keys
    ): mixed {

        if (is_object($usage)) {

            if (
                method_exists(
                    $usage,
                    'toArray'
                )
            ) {

                $usage =
                    $usage->toArray();

            } else {

                $usage =
                    (array) $usage;
            }
        }

        if (!is_array($usage)) {
            return null;
        }

        foreach ($keys as $key) {

            if (
                array_key_exists(
                    $key,
                    $usage
                )
            ) {

                return $usage[$key];
            }
        }

        foreach ($usage as $value) {

            if (
                is_array($value)
                ||
                is_object($value)
            ) {

                $nested =
                    $this->extractUsageValue(
                        $value,
                        ...$keys
                    );

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Ensure Financial Sources
    |--------------------------------------------------------------------------
    */

    private function ensureFinancialSources(
        array $data,
        SourceNormalizationService $sourceNormalizer
    ): array {

        $sections = [

            'balance_sheet' => [

                'cash',
                'cash_equivalents',
                'accounts_receivable',
                'inventory',
                'prepaid_expenses',
                'other_current_assets',
                'total_current_assets',
                'property_plant_equipment',
                'intangible_assets',
                'investments',
                'other_non_current_assets',
                'total_assets',
                'accounts_payable',
                'short_term_loans',
                'current_portion_long_term_debt',
                'other_current_liabilities',
                'total_current_liabilities',
                'long_term_loans',
                'other_non_current_liabilities',
                'total_liabilities',
                'share_capital',
                'retained_earnings',
                'reserves',
                'other_equity',
                'total_equity',
            ],

            'income_statement' => [

                'revenue',
                'cost_of_sales',
                'gross_profit',
                'operating_expenses',
                'operating_income',
                'finance_cost',
                'tax_expense',
                'net_profit',
            ],

            'cash_flow_statement' => [

                'operating_cash_flow',
                'investing_cash_flow',
                'financing_cash_flow',
                'opening_cash',
                'closing_cash',
                'net_cash_change',
            ],

            'equity_statement' => [

                'opening_equity',
                'capital',
                'reserves',
                'retained_earnings',
                'net_profit',
                'closing_equity',
            ],
        ];

        foreach ($sections as $sectionName => $keys) {

            $section =
                $data[$sectionName]
                ?? [];

            if (!is_array($section)) {
                $section = [];
            }

            foreach ($keys as $key) {
                if (! array_key_exists($key, $section) || $section[$key] === null) {
                    $section[$key] = [];
                    continue;
                }

                $values = $section[$key];

                if (! is_array($values) || $values === []) {
                    $section[$key] = [];
                    continue;
                }

                $normalizedValues = [];

                foreach ($values as $periodValue) {
                    if (! is_array($periodValue)) {
                        continue;
                    }

                    $periodId = $periodValue['period_id'] ?? null;

                    if ($periodId === null || trim((string) $periodId) === '') {
                        continue;
                    }

                    $value = array_key_exists('value', $periodValue)
                        ? $periodValue['value']
                        : null;

                    $confidence = is_numeric($periodValue['confidence'] ?? null)
                        ? (float) $periodValue['confidence']
                        : 0;

                    $source = $sourceNormalizer->normalizeSource(
                        $periodValue['source'] ?? null
                    );

                    $normalizedValues[] = [
                        'period_id' => (string) $periodId,
                        'value' => $value,
                        'confidence' => $confidence,
                        'source' => $source,
                    ];
                }

                $byPeriod = [];

                foreach ($normalizedValues as $periodValue) {
                    $periodId = $periodValue['period_id'];

                    if (! isset($byPeriod[$periodId])) {
                        $byPeriod[$periodId] = $periodValue;
                        continue;
                    }

                    if ($periodValue['confidence'] > $byPeriod[$periodId]['confidence']) {
                        $byPeriod[$periodId] = $periodValue;
                    }
                }

                $section[$key] = array_values($byPeriod);
            }

            $data[$sectionName] =
                $section;
        }

        if (
            ($data['document_type'] ?? '')
            === 'bank_statement'
        ) {

            $data['balance_sheet'] =
                null;

            $data['income_statement'] =
                null;

            $data['cash_flow_statement'] =
                null;

            $data['equity_statement'] =
                null;

            return $data;
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Ensure Bank Statement
    |--------------------------------------------------------------------------
    */

    private function ensureBankStatement(
        array $data,
        SourceNormalizationService $sourceNormalizer
    ): array {

        if (
            !isset(
                $data['bank_statement']
            )
            ||
            !is_array(
                $data['bank_statement']
            )
        ) {

            return $data;
        }

        $bank =
            $data['bank_statement'];

        $transactions =
            $bank['transactions']
            ?? [];

        $timestamps = [];

        foreach (
            $transactions
            as $idx => $tx
        ) {

            if (!is_array($tx)) {
                continue;
            }

            if (
                !array_key_exists(
                    'source',
                    $tx
                )
                ||
                $tx['source'] === null
            ) {

                $transactions[$idx]['source'] =
                    $sourceNormalizer->normalizeSource(
                        ['type' => 'pdf']
                    );

            } else {

                $transactions[$idx]['source'] =
                    $sourceNormalizer->normalizeSource(
                        $tx['source']
                    );
            }

            $dateStr =
                $tx['posting_date']
                ??
                $tx['value_date']
                ??
                null;

            if (
                $dateStr
                &&
                is_string($dateStr)
                &&
                trim($dateStr) !== ''
            ) {

                $ts =
                    strtotime($dateStr);

                if ($ts !== false) {

                    $timestamps[] =
                        $ts;
                }
            }
        }

        $bank['transactions'] =
            $transactions;

        $period =
            $bank['statement_period']
            ??
            [
                'from' => null,
                'to' => null,
            ];

        $fromNull =
            !isset($period['from'])
            ||
            $period['from'] === null;

        $toNull =
            !isset($period['to'])
            ||
            $period['to'] === null;

        if (
            ($fromNull || $toNull)
            &&
            count($timestamps) > 0
        ) {

            sort($timestamps);

            $period['from'] =
                date(
                    'Y-m-d',
                    $timestamps[0]
                );

            $period['to'] =
                date(
                    'Y-m-d',
                    $timestamps[
                        count($timestamps) - 1
                    ]
                );
        }

        $bank['statement_period'] =
            $period;

        $data['bank_statement'] =
            $bank;

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Extraction Model
    |--------------------------------------------------------------------------
    */

    protected function extractionModel(): string
    {
        return config(
            'ai.extraction.mode',
            'ocr'
        ) === 'vision'

            ? config(
                'ai.extraction.vision_model',
                config(
                    'ai.extraction.model',
                    'mistral-large-latest'
                )
            )

            : config(
                'ai.extraction.ocr_model',
                config(
                    'ai.extraction.model',
                    'mistral-ocr-latest'
                )
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Normalization Model
    |--------------------------------------------------------------------------
    */

    protected function normalizationModel(): string
    {
        $model =
            config(
                'ai.extraction.vision_model',
                config(
                    'ai.extraction.model',
                    'mistral-large-latest'
                )
            );

        if (
            is_string($model)
            &&
            str_contains(
                strtolower($model),
                'ocr'
            )
        ) {

            return 'mistral-large-latest';
        }

        return $model;
    }
}
