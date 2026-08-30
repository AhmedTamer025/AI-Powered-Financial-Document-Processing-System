<?php

namespace App\AI\Services;

use App\AI\DTOS\RawDocument;
use App\AI\Exceptions\ExtractionException;
use App\AI\Extractions\ExcelExtractionTool;
use App\AI\Extractions\VisionExtractionTool;
use App\AI\Extractions\WordExtractionTool;

class FileDispatcherService
{
    private array $lastUsage = [];

    public function __construct(
        private ExcelExtractionTool $excelExtractor,
        private WordExtractionTool $wordExtractor,
        private VisionExtractionTool $visionAgent,
    ) {
        $this->lastUsage = $this->emptyUsage();
    }

    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    public function extract(string $filePath): RawDocument
    {
        if (! file_exists($filePath)) {
            throw new ExtractionException(
                "File does not exist: {$filePath}"
            );
        }

        $extension = strtolower(
            pathinfo($filePath, PATHINFO_EXTENSION)
        );

        return match ($extension) {
            'xls',
            'xlsx',
            'csv',
            'ods'
                => $this->handleExcel($filePath),

            'doc',
            'docx'
                => $this->handleWord($filePath),

            'pdf',
            'jpg',
            'jpeg',
            'png',
            'webp'
                => $this->handleVision($filePath),

            default => throw new ExtractionException(
                "Unsupported file type: {$extension}"
            ),
        };
    }

    private function handleExcel(string $path): RawDocument
    {
        $document = $this->excelExtractor->extract($path);

        $this->lastUsage = $this->normalizeUsageData(
            $document->metadata['usage'] ?? []
        );

        return $document;
    }

    private function handleWord(string $path): RawDocument
    {
        $document = $this->wordExtractor->extract($path);

        $this->lastUsage = $this->normalizeUsageData(
            $document->metadata['usage'] ?? []
        );

        return $document;
    }

    private function handleVision(string $path): RawDocument
    {
        $document = $this->visionAgent->extract($path);

        $this->lastUsage = $this->normalizeUsageData(
            $document->metadata['usage'] ?? []
        );

        return $document;
    }

    private function emptyUsage(): array
    {
        return [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'cost' => null,
        ];
    }

    private function normalizeUsageData(mixed $usageData): array
    {
        if ($usageData === null) {
            return $this->emptyUsage();
        }

        if (is_object($usageData)) {
            if (method_exists($usageData, 'toArray')) {
                $usageData = $usageData->toArray();
            } else {
                $usageData = [
                    'input_tokens' =>
                        $usageData->inputTokens
                        ?? $usageData->input_tokens
                        ?? $usageData->prompt_tokens
                        ?? $usageData->promptTokens
                        ?? null,

                    'output_tokens' =>
                        $usageData->outputTokens
                        ?? $usageData->output_tokens
                        ?? $usageData->completion_tokens
                        ?? $usageData->completionTokens
                        ?? null,

                    'total_tokens' =>
                        $usageData->totalTokens
                        ?? $usageData->total_tokens
                        ?? null,

                    'cost' =>
                        $usageData->cost
                        ?? $usageData->total_cost
                        ?? $usageData->totalCost
                        ?? null,
                ];
            }
        }

        if (! is_array($usageData)) {
            return $this->emptyUsage();
        }

        $input =
            $usageData['input_tokens']
            ?? $usageData['prompt_tokens']
            ?? $usageData['promptTokens']
            ?? null;

        $output =
            $usageData['output_tokens']
            ?? $usageData['completion_tokens']
            ?? $usageData['completionTokens']
            ?? null;

        $total =
            $usageData['total_tokens']
            ?? $usageData['totalTokens']
            ?? (
                ($input !== null || $output !== null)
                    ? ($input ?? 0) + ($output ?? 0)
                    : null
            );

        return [
            'input_tokens' =>
                $input === null ? null : (int) $input,

            'output_tokens' =>
                $output === null ? null : (int) $output,

            'total_tokens' =>
                $total === null ? null : (int) $total,

            'cost' =>
                $usageData['cost']
                ?? $usageData['total_cost']
                ?? $usageData['totalCost']
                ?? null,
        ];
    }
}
