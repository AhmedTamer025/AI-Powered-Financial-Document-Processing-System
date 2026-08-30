<?php

namespace App\Jobs;


use App\AI\DTOS\NormalizedDocument;
use App\AI\DTOS\RawDocument;
use App\AI\Services\Financial\SaveBankStatementService;
use App\AI\Services\Financial\SaveFinancialStatementService;
use App\AI\Services\SourceNormalizationService;

use App\AI\Services\GeminDocumentNormalizationService;
use App\AI\Services\AiResultArchiveService;
use App\Models\AiResult;

use App\Enums\DocumentType;
use App\Enums\DocumentStatus;

use App\Models\BankStatement;
use App\Models\FinancialStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Laravel\Ai\Exceptions\RateLimitedException;

class NormalizeDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [
        60,
        120,
        300,
    ];

    public function __construct(
        public string $aiResultId
    ) {}

    public function handle(
        GeminDocumentNormalizationService $normalizer,
        SaveBankStatementService $bankService,
        SaveFinancialStatementService $financialService,
        AiResultArchiveService $archiver,
        SourceNormalizationService $sourceNormalizer
    ): void {

        $start = microtime(true);

        $aiResult = AiResult::findOrFail($this->aiResultId);

        if ($aiResult->status === DocumentStatus::Completed) {
            return;
        }

        $upload =
            $aiResult->bankStatement
            ??
            $aiResult->financialStatement;

        if (! $upload) {

            throw new \RuntimeException(
                'Upload record not found.'
            );
        }

        try {

            //Update Status


            $aiResult->update([
                'status' => DocumentStatus::Normalizing,
            ]);

            //Recover Raw Extraction (read directly from disk by reference/key, not from the stored URL)


            $rawJson = $archiver->getContent(
                $aiResult->id,
                'raw_extraction'
            );

            if (empty($rawJson)) {

                throw new \RuntimeException(
                    'Raw extraction file not found on disk.'
                );
            }

            $raw = json_decode($rawJson, true);

            if (empty($raw)) {

                throw new \RuntimeException(
                    'Raw extraction is missing or invalid.'
                );
            }

            //Rebuild RawDocument DTO


            $document = new RawDocument(

                fileName: $raw['file_name'],

                extension: $raw['extension'],

                filePath: $raw['file_path'],

                fileSize: (int) ($raw['file_size'] ?? 0),

                plainText: $raw['plain_text'],

                sections: $raw['sections'] ?? [],

                tables: $raw['tables'] ?? [],

                metadata: $raw['metadata'] ?? [],

                warnings: $raw['warnings'] ?? [],

            );

            // Gemini Normalization



            $normalized = $normalizer->normalize(
                $document,
                $aiResult->document_type?->label() ?? null
            );

            //Convert DTO -> Array


            $normalizedArray = $this->normalizedToArray(
                $normalized
            );

            // Post-process shapes
            $normalizedArray = $this->ensureFinancialSources($normalizedArray, $sourceNormalizer);
            $normalizedArray = $this->ensureBankStatement($normalizedArray, $sourceNormalizer);

            DB::transaction(function () use (

                $normalized,

                $normalizedArray,

                $aiResult,

                $upload,

                $bankService,

                $financialService,

                $archiver,

                $start

            ) {

                //Archive normalized result to disk, store only the URL in DB


                $normalizedResultPath = $archiver->store(
                    $aiResult->id,
                    'normalized_result',
                    $normalizedArray
                );

                $aiResult->update([

                    'document_type' =>
                    DocumentType::fromLabel($normalized->documentType),

                    'normalized_result' =>
                    $normalizedResultPath,

                    'overall_confidence' =>
                    $normalized->overallConfidence,

                    'warnings' =>
                    $normalized->warnings,

                    'status' =>
                    DocumentStatus::Completed,

                    'processing_time_ms' =>
                    (int)(
                        (microtime(true) - $start)
                        * 1000
                    ),

                ]);

                //Save Parsed Business Data
                //(Note: bank/financial services still receive $normalizedArray from memory, not from disk)


                $fileInfo = [

                    'original_file_name' => $upload->original_file_name,

                    'stored_file_name'   => $upload->stored_file_name,

                    'stored_path'        => $upload->stored_path,

                    'extension'          => $upload->extension,

                    'mime_type'          => $upload->mime_type,

                    'size'               => $upload->size,

                ];

                if ($upload instanceof BankStatement) {

                    $bankService->execute(

                        $normalizedArray,

                        $upload->business_id,

                        $aiResult->id,   // ai_result_id

                        $fileInfo

                    );
                } elseif ($upload instanceof FinancialStatement) {

                    $financialService->execute(

                        $normalizedArray,

                        $upload->business_id,

                        $aiResult->id,   // ai_result_id

                        $fileInfo

                    );
                }

                $aiResult->update([

                    'status' => DocumentStatus::Completed,

                ]);
            });
        } catch (RateLimitedException $e) {

            logger()->warning('AI rate limited during normalization, releasing job', [
                'normalization_id' => $this->aiResultId,
                'attempts' => method_exists($this, 'attempts') ? $this->attempts() : null,
                'error' => $e->getMessage(),
            ]);

            $base = max(1, method_exists($this, 'attempts') ? $this->attempts() : 1);
            $delay = min(180, max(60, 60 * $base));

            $this->release($delay);
            return;
        } catch (Throwable $e) {

            Log::error('Document normalization failed', [
                'normalization_id' => $this->aiResultId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {

                $aiResult->refresh();

                if ($aiResult->status === DocumentStatus::Completed) {
                    return;
                }

                $aiResult->update([

                    'status' => DocumentStatus::Failed,

                    'error_message' => $e->getMessage(),

                ]);

                $upload?->update([

                    'status' => DocumentStatus::Failed,

                ]);
            } catch (Throwable $ignored) {

                Log::error(
                    'Failed while updating failure status.',
                    [
                        'normalization_id' => $this->aiResultId,
                    ]
                );
            }

            throw $e;
        }
    }

    //Convert NormalizedDocument DTO to array.

    private function normalizedToArray(
        NormalizedDocument $normalized
    ): array {

        return [

            'document_type' =>
            $normalized->documentType,

            'overall_confidence' =>
            $normalized->overallConfidence,

            'warnings' =>
            $normalized->warnings,

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

        ];
    }

    ///Queue permanently failed.

    public function failed(Throwable $exception): void
    {

        Log::critical(

            'NormalizeUploadedDocumentJob permanently failed',

            [

                'normalization_id' => $this->aiResultId,

                'error' => $exception->getMessage(),

            ]

        );

        $aiResult = AiResult::query()->find(
            $this->aiResultId
        );

        if (! $aiResult) {
            return;
        }

        if ($aiResult->status === DocumentStatus::Completed) {
            return;
        }

        $aiResult->update([

            'status' => DocumentStatus::Failed,

            'error_message' => $exception->getMessage(),

        ]);

        $aiResult->source?->update([

            'status' => DocumentStatus::Failed,

        ]);
    }

    //Ensure each financial section field has a consistent structure

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

        foreach (
            $sections as $sectionName => $keys
        ) {

            $section =
                $data[$sectionName] ?? [];

            if (!is_array($section)) {
                $section = [];
            }

            foreach ($keys as $key) {

                /*
             * Field doesn't exist.
             *
             * Keep an empty array because financial
             * fields are now period-based.
             */
                if (
                    !array_key_exists(
                        $key,
                        $section
                    )
                    ||
                    $section[$key] === null
                ) {

                    $section[$key] = [];

                    continue;
                }

                $values = $section[$key];

                /*
             * New expected format:
             *
             * [
             *     [
             *         period_id,
             *         value,
             *         confidence,
             *         source
             *     ],
             *     ...
             * ]
             */
                if (
                    !is_array($values)
                    ||
                    empty($values)
                ) {

                    $section[$key] = [];

                    continue;
                }

                $normalizedValues = [];

                foreach ($values as $periodValue) {

                    if (
                        !is_array($periodValue)
                    ) {
                        continue;
                    }

                    $periodId =
                        $periodValue['period_id']
                        ?? null;

                    /*
                 * A financial value without a period
                 * cannot safely be associated with a
                 * reporting period.
                 */
                    if (
                        $periodId === null
                        ||
                        trim((string) $periodId) === ''
                    ) {
                        continue;
                    }

                    $value =
                        array_key_exists(
                            'value',
                            $periodValue
                        )
                        ? $periodValue['value']
                        : null;

                    $confidence =
                        is_numeric(
                            $periodValue['confidence'] ?? null
                        )
                        ? (float)
                        $periodValue['confidence']
                        : 0;

                    $source =
                        $periodValue['source']
                        ??
                        null;

                    $source =
                        $sourceNormalizer->normalizeSource(
                            $source
                        );

                    $normalizedValues[] = [

                        'period_id' =>
                        (string) $periodId,

                        'value' =>
                        $value,

                        'confidence' =>
                        $confidence,

                        'source' =>
                        $source,
                    ];
                }

                /*
             * Deduplicate by period_id.
             *
             * This is defensive. DocumentMergeService
             * should already do this, but the job should
             * not allow duplicates through.
             */
                $byPeriod = [];

                foreach (
                    $normalizedValues
                    as $periodValue
                ) {

                    $periodId =
                        $periodValue['period_id'];

                    if (
                        !isset(
                            $byPeriod[$periodId]
                        )
                    ) {

                        $byPeriod[$periodId] =
                            $periodValue;

                        continue;
                    }

                    /*
                 * Keep the value with the higher confidence.
                 */
                    $existing =
                        $byPeriod[$periodId];

                    if (
                        $periodValue['confidence']
                        >
                        $existing['confidence']
                    ) {

                        $byPeriod[$periodId] =
                            $periodValue;
                    }
                }

                $section[$key] =
                    array_values($byPeriod);
            }

            $data[$sectionName] =
                $section;
        }

        /*
     * Financial document should not contain bank data.
     */
        if (
            ($data['document_type'] ?? '')
            !== 'bank_statement'
        ) {
            return $data;
        }

        $data['balance_sheet'] = null;
        $data['income_statement'] = null;
        $data['cash_flow_statement'] = null;
        $data['equity_statement'] = null;

        return $data;
    }

    //Populate bank statement statement_period from transactions and ensure transaction source key

    private function ensureBankStatement(array $data, SourceNormalizationService $sourceNormalizer): array
    {
        if (!isset($data['bank_statement']) || !is_array($data['bank_statement'])) {
            return $data;
        }

        $bank = $data['bank_statement'];
        $transactions = $bank['transactions'] ?? [];
        $timestamps = [];

        foreach ($transactions as $idx => $tx) {
            if (!array_key_exists('source', $tx) || $tx['source'] === null) {
                $transactions[$idx]['source'] = $sourceNormalizer->normalizeSource(['type' => 'pdf']);
            } else {
                $transactions[$idx]['source'] = $sourceNormalizer->normalizeSource($tx['source']);
            }

            $dateStr = $tx['posting_date'] ?? $tx['value_date'] ?? null;
            if ($dateStr && is_string($dateStr) && trim($dateStr) !== '') {
                $ts = strtotime($dateStr);
                if ($ts !== false) {
                    $timestamps[] = $ts;
                }
            }
        }

        $bank['transactions'] = $transactions;

        $period = $bank['statement_period'] ?? ['from' => null, 'to' => null];
        $fromNull = !isset($period['from']) || $period['from'] === null;
        $toNull = !isset($period['to']) || $period['to'] === null;

        if (($fromNull || $toNull) && count($timestamps) > 0) {
            sort($timestamps);
            $period['from'] = date('Y-m-d', $timestamps[0]);
            $period['to'] = date('Y-m-d', $timestamps[count($timestamps) - 1]);
        }

        $bank['statement_period'] = $period;
        $data['bank_statement'] = $bank;

        return $data;
    }
}
