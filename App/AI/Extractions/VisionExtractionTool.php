<?php

namespace App\AI\Extractions;

use App\AI\Clients\VisionClient;
use App\AI\DTOS\RawDocument;

class VisionExtractionTool
{
    public function __construct(
        private VisionClient $client,
    ) {}

    public function extract(string $path): RawDocument
    {
        $result = $this->client->extract($path);

        if (($result['usage_info']['mode'] ?? null) === 'vision') {
            return $this->buildFromVisionResult($path, $result);
        }

        return $this->buildFromOcrResult($path, $result);
    }

    /**
     * Original Mistral OCR builder. Do not share this with vision.
     */
    private function buildFromOcrResult(string $path, array $result): RawDocument
    {
        $plainText = '';
        $sections = [];
        $tables = [];
        $warnings = [];

        foreach ($result['pages'] as $page) {
            $markdown = $page['markdown'] ?? '';

            foreach ($page['tables'] ?? [] as $tableIndex => $table) {
                $placeholder = '[' . $table['id'] . '](' . $table['id'] . ')';

                $markdown = str_replace(
                    $placeholder,
                    PHP_EOL . $table['content'] . PHP_EOL,
                    $markdown
                );

                $tables[] = [
                    'id' => $table['id'],
                    'page' => ((int) ($page['index'] ?? 0)) + 1,
                    'table' => $tableIndex + 1,
                    'content' => $table['content'],
                    'format' => $table['format'] ?? 'markdown',
                ];
            }

            $plainText .= $markdown . PHP_EOL . PHP_EOL;

            $sections[] = [
                'page' => $page['index'],
                'markdown' => $markdown,
                'blocks' => $page['blocks'] ?? [],
                'confidence' => $page['confidence_scores'] ?? [],
            ];

            $confidence =
                $page['confidence_scores']['average_page_confidence_score']
                ?? 1;

            if ($confidence < 0.8) {
                $warnings[] =
                    'Low OCR confidence on page '
                    . ($page['index'] + 1);
            }
        }

        return new RawDocument(
            fileName: basename($path),
            extension: strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            filePath: realpath($path) ?: $path,
            fileSize: filesize($path),
            plainText: trim($plainText),
            sections: $sections,
            tables: $tables,
            metadata: [
                'provider' => config('ai.extraction.provider', 'mistral'),
                'model' => $result['model'] ?? config('ai.extraction.ocr_model', config('ai.extraction.model', 'mistral-ocr-latest')),
                'mode' => 'ocr',
                'pages' => count($result['pages']),
                'usage' => is_array($result['usage_info'] ?? null) ? $result['usage_info'] : (is_array($result['usage'] ?? null) ? $result['usage'] : []),
                'created_at' => now()->toISOString(),
            ],
            warnings: $warnings,
        );
    }

    /**
     * Vision agent output, already normalized to pages[].
     */
    private function buildFromVisionResult(string $path, array $result): RawDocument
    {
        if (!isset($result['pages']) || !is_array($result['pages'])) {
            throw new \RuntimeException(
                'Vision extraction result is missing OCR-compatible pages[].'
            );
        }

        $plainText = '';
        $sections = [];
        $tables = [];
        $warnings = [];

        foreach ($result['pages'] as $page) {
            if (!is_array($page)) {
                continue;
            }

            $markdown = $page['markdown'] ?? '';

            foreach ($page['tables'] ?? [] as $tableIndex => $table) {
                if (!is_array($table)) {
                    continue;
                }

                $tableId = $table['id'] ?? ('tbl-' . $tableIndex . '.md');
                $content = $table['content'] ?? '';

                if ($content === '') {
                    continue;
                }

                $placeholder = '[' . $tableId . '](' . $tableId . ')';

                $markdown = str_replace(
                    $placeholder,
                    PHP_EOL . $content . PHP_EOL,
                    $markdown
                );

                $tables[] = [
                    'id' => $tableId,
                    'page' => ((int) ($page['index'] ?? 0)) + 1,
                    'table' => $tableIndex + 1,
                    'content' => $content,
                    'format' => $table['format'] ?? 'markdown',
                ];
            }

            $plainText .= $markdown . PHP_EOL . PHP_EOL;

            $sections[] = [
                'page' => $page['index'] ?? 0,
                'markdown' => $markdown,
                'blocks' => $page['blocks'] ?? [],
                'confidence' => $page['confidence_scores'] ?? [],
            ];

            $confidence =
                $page['confidence_scores']['average_page_confidence_score']
                ?? 1;

            if (is_numeric($confidence) && (float) $confidence < 0.8) {
                $warnings[] =
                    'Low extraction confidence on page '
                    . (((int) ($page['index'] ?? 0)) + 1);
            }
        }

        $usage = is_array($result['usage_info'] ?? null)
            ? $result['usage_info']
            : [];

        return new RawDocument(
            fileName: basename($path),
            extension: strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            filePath: realpath($path) ?: $path,
            fileSize: filesize($path),
            plainText: trim($plainText),
            sections: $sections,
            tables: $tables,
            metadata: [
                'provider' => config('ai.extraction.provider', 'mistral'),
                'model' => $result['model'] ?? config('ai.extraction.vision_model', config('ai.extraction.model', 'mistral-large-latest')),
                'pages' => count($result['pages']),
                'usage' => is_array($result['usage_info'] ?? null) ? $result['usage_info'] : (is_array($result['usage'] ?? null) ? $result['usage'] : []),
                'mode' => 'vision',
                'source' => $usage['source'] ?? 'extraction_agent',
                'created_at' => now()->toISOString(),
            ],
            warnings: $warnings,
        );
    }
}
