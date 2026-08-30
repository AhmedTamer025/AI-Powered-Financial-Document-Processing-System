<?php

namespace App\AI\Services;

use App\AI\Agents\BankStatementNormalizationAgent;
use App\AI\Agents\FinancialStatementNormalizationAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use App\AI\Builders\BankStatementPromptBuilder;
use App\AI\Builders\ExcelContentBuilder;
use App\AI\Builders\FinancialStatementPromptBuilder;
use App\AI\Builders\OcrContentBuilder;
use App\AI\Chunking\DocumentChunker;
use App\AI\DTOS\RawDocument;

class DocumentNormalizationRouter
{
    private array $lastUsage = [];

    public function __construct(
        private DocumentChunker $chunker,
    ) {}

    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    public function normalize(
        RawDocument $document,
        string $documentType
    ): array {


        $content = match ($document->extension) {

            'xlsx',
            'xls',
            'csv',
            'ods'
            => ExcelContentBuilder::build($document),


            default
            => OcrContentBuilder::build($document),
        };
        $content = Utf8Sanitizer::clean($content);

        //try with chunk 

        $chunks = $this->chunker->chunk(
            $document,
            $content
        );

        foreach ($chunks as $chunk) {

            logger()->info('CHUNK', [
                'index' => $chunk->index,
                'total' => $chunk->total,
                'length' => strlen($chunk->content),
                'start' => substr($chunk->content, 0, 200),
                'end' => substr($chunk->content, -200),
            ]);
        }

        // try without chunk 
        /* $chunks = [
            new \App\AI\Chunking\DocumentChunk(
                index: 1,
                total: 1,
                content: $content,
                containsHeader: true,
                isLast: true,
            )
        ]; */

        $responses = [];
        $this->lastUsage = $this->emptyUsage();

        foreach ($chunks as $chunk) {


            $prompt = match ($documentType) {


                'bank_statement'
                =>
                BankStatementPromptBuilder::build(
                    $document,
                    $chunk
                ),


                'financial_statement'
                =>
                FinancialStatementPromptBuilder::build(
                    $document,
                    $chunk
                ),



                default =>
                throw new \RuntimeException(
                    "Unsupported type"
                ),
            };

            $start = microtime(true);

            $prompt = Utf8Sanitizer::clean($prompt);
            $responseData = $this->callAgentWithRetry(
                $documentType,
                $prompt
            );

            logger()->info('Chunk finished', [
                'chunk' => $chunk->index,
                'seconds' => round(
                    microtime(true) - $start,
                    2
                ),
            ]);

            $response = $responseData['response'] ?? null;
            $usage = $responseData['usage'] ?? $this->emptyUsage();
            $decodedResponse = $responseData['decoded'] ?? [];

            if (!is_array($decodedResponse)) {
                throw new \RuntimeException('Bank statement normalization returned invalid JSON.');
            }

            $this->lastUsage = $this->mergeUsage($this->lastUsage, $usage);

            $responses[] = $this->enrichBankTransactionSources(
                $decodedResponse,
                $chunk->content,
                $document->extension,
                $document->tables
            );
        }

        return $responses;
    }

    private function emptyUsage(): array
    {
        return [
            'input_tokens' => null,
            'output_tokens' => null,
            'reasoning_tokens' => null,
            'total_tokens' => null,
            'cost' => null,
        ];
    }

    private function mergeUsage(array $base, array $incoming): array
    {
        foreach (['input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens', 'cost'] as $key) {
            if (($incoming[$key] ?? null) === null) {
                continue;
            }

            if (($base[$key] ?? null) === null) {
                $base[$key] = $incoming[$key];
                continue;
            }

            if (is_numeric($base[$key]) && is_numeric($incoming[$key])) {
                $base[$key] = (float) $base[$key] + (float) $incoming[$key];
            }
        }

        return $base;
    }

    private function enrichBankTransactionSources(
        array $response,
        string $chunkContent,
        string $extension,
        array $tables
    ): array {
        if (($response['bank_statement']['transactions'] ?? null) === null) {
            return $response;
        }

        foreach ($response['bank_statement']['transactions'] as $index => $transaction) {
            $source = $transaction['source'] ?? null;
            $page = is_array($source) ? ($source['page'] ?? null) : null;

            if ($page === null || (int) $page <= 0) {
                $position = $this->findTransactionPosition($chunkContent, $transaction);
                $page = $this->pageAtPosition($chunkContent, $position);

                if ($page === null) {
                    $page = $this->singlePageInChunk($chunkContent);
                }
            }

            if ($page === null) {
                continue;
            }

            if (!is_array($source)) {
                $source = [];
            }

            $source['type'] = $source['type'] ?? ($extension === 'pdf' ? 'pdf' : 'ocr');
            $source['page'] = $page;

            $tableMetadata = $this->tableMetadataForTransaction($transaction, $tables, $page);

            if ($tableMetadata !== null) {
                $source['type'] = 'table';
                $source['table'] = $tableMetadata['table'];
                $source['label'] = $source['label'] ?? 'Transaction Row';
            }

            $response['bank_statement']['transactions'][$index]['source'] = $source;
        }

        return $response;
    }

    private function tableMetadataForTransaction(
        array $transaction,
        array $tables,
        int $page
    ): ?array {
        $searchTerms = array_filter([
            $transaction['reference'] ?? null,
            $transaction['description'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && mb_strlen(trim($value)) >= 8);

        foreach ($tables as $table) {
            if ((int) ($table['page'] ?? 0) !== $page) {
                continue;
            }

            $content = (string) ($table['content'] ?? '');

            foreach ($searchTerms as $searchTerm) {
                if (stripos($content, trim($searchTerm)) !== false) {
                    return [
                        'table' => (int) ($table['table'] ?? 1),
                    ];
                }
            }
        }

        return null;
    }

    private function findTransactionPosition(string $content, array $transaction): ?int
    {
        foreach (['reference', 'description'] as $field) {
            $term = trim((string) ($transaction[$field] ?? ''));

            if (mb_strlen($term) < 8) {
                continue;
            }

            $position = stripos($content, $term);

            if ($position !== false) {
                return $position;
            }
        }

        return null;
    }

    private function pageAtPosition(string $content, ?int $position): ?int
    {
        if ($position === null) {
            return null;
        }

        $prefix = substr($content, 0, $position);

        if (preg_match_all('/={20,}\R?PAGE\s+(\d+)\R?={20,}/i', $prefix, $matches) === false) {
            return null;
        }

        if ($matches[1] === []) {
            return null;
        }

        return (int) end($matches[1]);
    }

    private function singlePageInChunk(string $content): ?int
    {
        if (preg_match_all('/={20,}\R?PAGE\s+(\d+)\R?={20,}/i', $content, $matches) !== 1) {
            return null;
        }

        return isset($matches[1][0]) ? (int) $matches[1][0] : null;
    }
    private function callAgentWithRetry(
        string $documentType,
        string $prompt
    ): array
    {
        $timeout = (int) config('ai.normalization.timeout', 300);
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = match ($documentType) {
                    'bank_statement' => (new BankStatementNormalizationAgent())->prompt(
                        $prompt,
                        timeout: $timeout,
                    ),
                    'financial_statement' => (new FinancialStatementNormalizationAgent())->prompt(
                        $prompt,
                        timeout: $timeout,
                    ),
                };

                return [
                    'response' => $response,
                    'decoded' => $this->decodeResponse($response),
                    'usage' => $this->extractUsageFromResponse($response),
                ];
            } catch (\Throwable $e) {
                $isRateLimited = $e instanceof RateLimitedException;

                logger()->warning('AI failed', [
                    'attempt' => $attempt,
                    'rate_limited' => $isRateLimited,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt === $maxAttempts) {
                    throw $e;
                }

                $base = (int) pow(2, $attempt);
                $wait = $isRateLimited ? $base * 2 : $base;
                $jitter = (int) max(0, round($wait * (rand(-30, 30) / 100)));
                $sleepFor = max(1, $wait + $jitter);

                logger()->info('AI backoff', ['attempt' => $attempt, 'sleep' => $sleepFor]);
                sleep($sleepFor);
            }
        }

        throw new \RuntimeException('AI normalization request failed after max attempts');
    }

    private function decodeResponse(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response) && isset($response->structured)) {
            $value = $response->structured;

            if (is_array($value)) {
                return $value;
            }

            if (is_object($value) && method_exists($value, 'toArray')) {
                return $value->toArray();
            }
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            $data = $response->toArray();
            return is_array($data) ? ($data['structured'] ?? $data) : [];
        }

        $data = json_decode((string) $response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function extractUsageFromResponse(mixed $response): array
    {
        if (!is_object($response)) {
            return $this->emptyUsage();
        }

        $usage = $response->usage ?? null;

        if ($usage === null && isset($response->steps) && is_array($response->steps)) {
            $merged = $this->emptyUsage();

            foreach ($response->steps as $step) {
                if (!is_object($step) || !isset($step->usage)) {
                    continue;
                }

                $merged = $this->mergeUsage($merged, $this->normalizeUsageData($step->usage));
            }

            return $merged;
        }

        return $this->normalizeUsageData($usage ?? []);
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
                    'input_tokens' => $usageData->inputTokens ?? $usageData->input_tokens ?? $usageData->prompt_tokens ?? $usageData->promptTokens ?? null,
                    'output_tokens' => $usageData->outputTokens ?? $usageData->output_tokens ?? $usageData->completion_tokens ?? $usageData->completionTokens ?? null,
                    'reasoning_tokens' => $usageData->reasoningTokens ?? $usageData->reasoning_tokens ?? null,
                    'total_tokens' => $usageData->totalTokens ?? $usageData->total_tokens ?? null,
                    'cost' => $usageData->cost ?? $usageData->total_cost ?? $usageData->totalCost ?? null,
                ];
            }
        }

        if (!is_array($usageData)) {
            return $this->emptyUsage();
        }

        $input = $usageData['input_tokens'] ?? $usageData['prompt_tokens'] ?? $usageData['promptTokens'] ?? null;
        $output = $usageData['output_tokens'] ?? $usageData['completion_tokens'] ?? $usageData['completionTokens'] ?? null;
        $reasoning = $usageData['reasoning_tokens'] ?? $usageData['reasoningTokens'] ?? null;
        $total = $usageData['total_tokens'] ?? $usageData['totalTokens'] ?? (($input !== null || $output !== null || $reasoning !== null) ? ($input ?? 0) + ($output ?? 0) + ($reasoning ?? 0) : null);

        return [
            'input_tokens' => $input === null ? null : (int) $input,
            'output_tokens' => $output === null ? null : (int) $output,
            'reasoning_tokens' => $reasoning === null ? null : (int) $reasoning,
            'total_tokens' => $total === null ? null : (int) $total,
            'cost' => $usageData['cost'] ?? $usageData['total_cost'] ?? $usageData['totalCost'] ?? null,
        ];
    }
}
