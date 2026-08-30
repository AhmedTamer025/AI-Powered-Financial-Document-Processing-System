<?php

namespace App\AI\Benchmark;

use App\Models\BenchmarkDataset;
use App\Models\BenchmarkDatasetFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BenchmarkDatasetLoader
{
    public function loadDatabaseDataset(
        string $datasetId,
        string $version = 'v1'
    ): BenchmarkDataset {
        $dataset = BenchmarkDataset::query()
            ->with('files')
            ->where('dataset_id', $datasetId)
            ->where('version', $version)
            ->first();

        if ($dataset) {
            return $dataset;
        }

        return $this->importFromStorage(
            $datasetId,
            $version
        );
    }

    protected function importFromStorage(
        string $datasetId,
        string $version
    ): BenchmarkDataset {
        $basePath = storage_path(
            "app/benchmarks/{$version}"
        );

        $possibleTypes = [
            'bank_statements',
            'financial_statements',
        ];

        $datasetPath = null;
        $documentType = null;

        foreach ($possibleTypes as $type) {
            $path =
                $basePath .
                DIRECTORY_SEPARATOR .
                $type .
                DIRECTORY_SEPARATOR .
                $datasetId;

            if (is_dir($path)) {
                $datasetPath = $path;

                $documentType =
                    $type === 'bank_statements'
                        ? 'bank_statement'
                        : 'financial_statement';

                break;
            }
        }

        if ($datasetPath === null) {
            throw new RuntimeException(
                "Benchmark dataset [{$datasetId}] does not exist in database " .
                "and was not found in storage."
            );
        }

        /*
         * Load metadata.json
         */
        $metadataPath =
            $datasetPath .
            DIRECTORY_SEPARATOR .
            'metadata.json';

        if (!file_exists($metadataPath)) {
            throw new RuntimeException(
                "metadata.json not found for benchmark dataset [{$datasetId}]."
            );
        }

        $metadata = json_decode(
            file_get_contents($metadataPath),
            true
        );

        if (!is_array($metadata)) {
            throw new RuntimeException(
                "Invalid metadata.json for benchmark dataset [{$datasetId}]."
            );
        }

        /*
         * Load ground_truth.json
         */
        $groundTruthPath =
            $datasetPath .
            DIRECTORY_SEPARATOR .
            'ground_truth.json';

        if (!file_exists($groundTruthPath)) {
            throw new RuntimeException(
                "ground_truth.json not found for benchmark dataset [{$datasetId}]."
            );
        }

        $groundTruth = json_decode(
            file_get_contents($groundTruthPath),
            true
        );

        if (!is_array($groundTruth)) {
            throw new RuntimeException(
                "Invalid ground_truth.json for benchmark dataset [{$datasetId}]."
            );
        }

        /*
         * Metadata can override document type.
         */
        $documentType =
            $metadata['document_type']
            ?? $documentType;

        /*
         * Find actual benchmark files.
         *
         * glob() returns STRING paths.
         *
         * Example:
         * C:\...\bank_001\document2.pdf
         */
        $files = collect(
            glob(
                $datasetPath .
                DIRECTORY_SEPARATOR .
                '*'
            )
        )
            ->filter(function (string $path) {
                return is_file($path)
                    && !in_array(
                        basename($path),
                        [
                            'metadata.json',
                            'ground_truth.json',
                        ],
                        true
                    );
            })
            ->values();

        if ($files->isEmpty()) {
            throw new RuntimeException(
                "No benchmark document found for [{$datasetId}]."
            );
        }

        /*
         * Create dataset and files.
         */
        return DB::transaction(function () use (
            $datasetId,
            $version,
            $metadata,
            $documentType,
            $groundTruth,
            $files
        ) {
            $dataset = BenchmarkDataset::updateOrCreate(
                [
                    'dataset_id' => $datasetId,
                    'version' => $version,
                ],
                [
                    'name' =>
                        $metadata['name']
                        ?? $datasetId,

                    'description' =>
                        $metadata['description']
                        ?? null,

                    'document_type' =>
                        $documentType,

                    'status' =>
                        'active',

                    'metadata' =>
                        $metadata,
                ]
            );

            /*
             * IMPORTANT:
             *
             * $file is a STRING path.
             *
             * Do NOT use:
             *
             * $file->getFilename()
             * $file->getPathname()
             *
             * Use basename($file).
             */
            foreach ($files as $file) {
                $filename = basename($file);

                $fileGroundTruth =
                    $this->groundTruthForFile(
                        $groundTruth,
                        $filename
                    );

                BenchmarkDatasetFile::updateOrCreate(
                    [
                        'benchmark_dataset_id' =>
                            $dataset->id,

                        'filename' =>
                            $filename,
                    ],
                    [
                        'path' =>
                            $file,

                        'document_type' =>
                            $documentType,

                        'metadata' =>
                            $metadata,

                        'ground_truth' =>
                            $fileGroundTruth,
                    ]
                );
            }

            return $dataset->fresh([
                'files',
            ]);
        });
    }

    protected function groundTruthForFile(
        array $groundTruth,
        string $filename
    ): array {
        /*
         * Single-file ground truth.
         */
        if (
            isset($groundTruth['dataset_id'])
            || isset($groundTruth['prediction'])
            || isset($groundTruth['document_type'])
        ) {
            return $groundTruth;
        }

        /*
         * Ground truth keyed by filename.
         */
        if (
            isset($groundTruth[$filename])
            && is_array($groundTruth[$filename])
        ) {
            return $groundTruth[$filename];
        }

        /*
         * Ground truth under "files".
         */
        if (
            isset($groundTruth['files'])
            && is_array($groundTruth['files'])
            && isset($groundTruth['files'][$filename])
        ) {
            return $groundTruth['files'][$filename];
        }

        /*
         * Fallback.
         */
        return $groundTruth;
    }
}
