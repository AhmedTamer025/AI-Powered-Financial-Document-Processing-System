<?php

namespace App\AI\Benchmark;

class BenchmarkDataset
{
    public function __construct(
        public readonly string $id,
        public readonly string $documentPath,
        public readonly array $groundTruth,
        public readonly array $metadata,
    ) {
    }

    public function documentType(): string
    {
        return $this->metadata['document_type']
            ?? $this->groundTruth['document_type']
            ?? 'unknown';
    }
}
