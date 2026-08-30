<?php

namespace App\AI\Benchmark;

use App\AI\Services\DocumentNormalizationRouter;
use App\AI\Services\FileDispatcherService;
use RuntimeException;

class BenchmarkRunner
{
    public function __construct(
        private BenchmarkDatasetLoader $loader,
        private FileDispatcherService $fileDispatcher,
        private DocumentNormalizationRouter $normalizationRouter,
        private BenchmarkEvaluator $evaluator,
    ) {
    }

    public function run(
        string $version = 'v1'
    ): array {
        $datasets =
            $this->loader->load($version);

        if (empty($datasets)) {
            return [];
        }

        $results = [];

        foreach ($datasets as $dataset) {
            $results[] =
                $this->runDataset($dataset);
        }

        return $results;
    }

    public function runDataset(
        BenchmarkDataset $dataset
    ): array {
        if (
            !file_exists(
                $dataset->documentPath
            )
        ) {
            throw new RuntimeException(
                "Benchmark document does not exist: {$dataset->documentPath}"
            );
        }

        $rawDocument =
            $this->fileDispatcher->extract(
                $dataset->documentPath
            );

        $chunkPredictions =
            $this->normalizationRouter->normalize(
                $rawDocument,
                $dataset->documentType()
            );

        $prediction =
            $this->mergeChunkPredictions(
                $chunkPredictions
            );

        $prediction =
            $this->normalizePredictionForBenchmark(
                $prediction,
                $dataset->documentType()
            );

        $evaluation =
            $this->evaluator->evaluate(
                $dataset->groundTruth,
                $prediction
            );

        return [
            'dataset_id' =>
                $dataset->id,

            'document_type' =>
                $dataset->documentType(),

            'document' =>
                $dataset->documentPath,

            'prediction' =>
                $prediction,

            'ground_truth' =>
                $dataset->groundTruth,

            'evaluation' =>
                $evaluation,
        ];
    }

    private function normalizePredictionForBenchmark(
        array $prediction,
        string $documentType
    ): array {
        if ($documentType === 'bank_statement') {
            return $this->normalizeBankStatementPrediction(
                $prediction
            );
        }

        return $prediction;
    }

    private function normalizeBankStatementPrediction(
        array $prediction
    ): array {
        $bank =
            $prediction['bank_statement'] ?? [];

        return [
            'document_type' =>
                $prediction['document_type']
                ?? 'bank_statement',

            'account' => [
                'account_name' =>
                    $bank['account_holder'] ?? null,

                'currency' =>
                    $bank['currency'] ?? null,

                'iban' =>
                    $bank['iban'] ?? null,

                'account_number' =>
                    $bank['account_number'] ?? null,

                'customer_id' =>
                    $bank['branch'] ?? null,
            ],

            'reporting_period' => [
                'start_date' =>
                    $this->normalizeBankDate(
                        $bank['statement_from'] ?? null
                    ),

                'end_date' =>
                    $this->normalizeBankDate(
                        $bank['statement_to'] ?? null
                    ),
            ],

            'opening_balance' => [
                'value' =>
                    $bank['opening_balance'] ?? null,
            ],

            'transactions' =>
                $this->normalizeTransactions(
                    $bank['transactions'] ?? []
                ),

            'closing_balance' => [
                'value' =>
                    $bank['closing_balance'] ?? null,
            ],
        ];
    }

    private function normalizeTransactions(
        array $transactions
    ): array {
        $result = [];

        foreach ($transactions as $transaction) {
            $description = trim(
                (string) (
                    $transaction['description']
                    ?? ''
                )
            );

            if (
                strtoupper($description) ===
                    'OPENING BALANCE' ||
                strtoupper($description) ===
                    'CLOSING BALANCE'
            ) {
                continue;
            }

            $result[] = [
                'date' =>
                    $this->normalizeBankDate(
                        $transaction['posting_date']
                        ?? null
                    ),

                'description' =>
                    $transaction['description']
                    ?? null,

                'debit' =>
                    $transaction['debit'] ?? null,

                'credit' =>
                    $transaction['credit'] ?? null,

                'balance' =>
                    $transaction['balance'] ?? null,
            ];
        }

        return $result;
    }

    private function normalizeBankDate(
        mixed $date
    ): ?string {
        if (
            $date === null ||
            trim((string) $date) === ''
        ) {
            return null;
        }

        $date = strtoupper(
            trim((string) $date)
        );

        foreach ([
            'Y-m-d',
            'dMY',
            'd-M-Y',
            'd/m/Y',
            'd/m/y',
            'Y/m/d',
        ] as $format) {
            $parsed =
                \DateTime::createFromFormat(
                    $format,
                    $date
                );

            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    private function mergeChunkPredictions(
        array $chunkPredictions
    ): array {
        $merged = [];

        foreach ($chunkPredictions as $prediction) {
            if (!is_array($prediction)) {
                continue;
            }

            $merged =
                $this->mergeRecursive(
                    $merged,
                    $prediction
                );
        }

        return $merged;
    }

    private function mergeRecursive(
        array $base,
        array $incoming
    ): array {
        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $base[$key] = $value;
                continue;
            }

            if (
                $key === 'transactions' &&
                is_array($base[$key]) &&
                is_array($value)
            ) {
                $base[$key] =
                    array_merge(
                        $base[$key],
                        $value
                    );

                continue;
            }

            if (
                is_array($base[$key]) &&
                is_array($value)
            ) {
                $base[$key] =
                    $this->mergeRecursive(
                        $base[$key],
                        $value
                    );

                continue;
            }

            if (
                (
                    $base[$key] === null ||
                    $base[$key] === ''
                ) &&
                $value !== null &&
                $value !== ''
            ) {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
