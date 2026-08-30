<?php

namespace App\AI\Chunking;

use App\AI\DTOS\RawDocument;

class DocumentChunker
{
    private const MAX_CHARACTERS = 12000;

    private const EXCEL_CONTEXT_ROWS = 15;


    public function chunk(RawDocument $document, string $content): array
    {
        if ($document->extension === 'pdf' || in_array($document->extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            if (mb_strlen($content) <= self::MAX_CHARACTERS) {
                return [
                    new DocumentChunk(
                        index: 1,
                        total: 1,
                        content: $content,
                        containsHeader: true,
                        isLast: true,
                        sourceMetadata: [
                            'type' => 'pdf',
                            'page' => 1,
                        ]
                    )
                ];
            }

            return $this->chunkPdf($content);
        }

        return $this->chunkSpreadsheet($content);
    }

    /**
     * PDF / OCR
     */
    private function chunkPdf(string $content): array
    {
        preg_match_all(
            '/={20,}\RPAGE\s+\d+\R={20,}.*?(?=(?:={20,}\RPAGE\s+\d+\R={20,})|\z)/si',
            $content,
            $matches
        );

        $pages = $matches[0];

        if ($pages === []) {
            return $this->createChunks([$content]);
        }

        $chunks = [];
        $buffer = '';

        foreach ($pages as $page) {

            if (
                mb_strlen($buffer . $page) > self::MAX_CHARACTERS
                && $buffer !== ''
            ) {
                $chunks[] = $buffer;
                $buffer = '';
            }

            $buffer .= $page . PHP_EOL;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $this->createChunks($chunks);
    }

    /**
     * Excel / CSV / ODS
     *
     * Splitting rules match the original chunker so the number
     * of chunks stays the same. Period context is attached as
     * metadata only and is not injected into chunk content.
     */
    private function chunkSpreadsheet(string $content): array
    {
        preg_match_all(
            '/={10,}\R\s*SHEET:\s*(.*?)\R={10,}\R(.*?)(?=(?:={10,}\R\s*SHEET:)|\z)/si',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            return [
                new DocumentChunk(
                    index: 1,
                    total: 1,
                    content: trim($content),
                    containsHeader: true,
                    isLast: true,
                    sourceMetadata: [
                        'type' => 'excel',
                    ],
                )
            ];
        }

        $parts = [];

        foreach ($matches as $sheet) {

            $sheetName = trim($sheet[1]);
            $sheetContent = trim($sheet[2]);
            $periodContext = $this->extractExcelContext($sheetContent);

            $header = "SHEET: {$sheetName}\n";
            $buffer = $header;
            $first = true;
            $rowCount = 0;

            foreach (preg_split('/\R/', $sheetContent) as $line) {
                if (trim($line) === '') {
                    $buffer .= PHP_EOL;
                    continue;
                }

                if (str_starts_with($line, 'ROW ')) {
                    $rowCount++;
                }

                if (
                    mb_strlen($buffer . $line . PHP_EOL) > self::MAX_CHARACTERS
                    || $rowCount > 80
                ) {
                    $parts[] = [
                        'sheet' => $sheetName,
                        'first' => $first,
                        'content' => trim($buffer),
                        'period_context' => $periodContext,
                    ];

                    $buffer = $header;
                    $first = false;
                    $rowCount = str_starts_with($line, 'ROW ') ? 1 : 0;
                    continue;
                }

                $buffer .= $line . PHP_EOL;
            }

            if (trim($buffer) !== $header) {
                $parts[] = [
                    'sheet' => $sheetName,
                    'first' => $first,
                    'content' => trim($buffer),
                    'period_context' => $periodContext,
                ];
            }
        }

        $total = count($parts);
        $chunks = [];

        foreach ($parts as $i => $part) {
            $chunks[] = new DocumentChunk(
                index: $i + 1,
                total: $total,
                content: $part['content'],
                sourceMetadata: [
                    'type' => 'excel',
                    'sheet' => $part['sheet'],
                    'period_context' => $part['period_context'],
                ],
                containsHeader: $part['first'],
                isLast: $i === ($total - 1),
                sheetName: $part['sheet'],
                isFirstSheetChunk: $part['first'],
                periodContext: $part['period_context']['columns'] ?? [],
            );
        }

        return $chunks;
    }

    /**
     * Keep the original top-of-sheet header text so the model
     * can map Excel columns to reporting periods.
     */
    private function extractExcelContext(string $sheetContent): array
    {
        $lines = preg_split('/\R/', $sheetContent);
        $contextLines = [];
        $columns = [];
        $rowCounter = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $contextLines[] = $line;

            if (str_starts_with($line, 'ROW ')) {
                $rowCounter++;
            }

            if ($rowCounter >= self::EXCEL_CONTEXT_ROWS) {
                break;
            }
        }

        foreach ($contextLines as $line) {
            preg_match_all(
                '/\b([A-Z]{1,3})(\d+)=([^\|]+)/',
                $line,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $column = $match[1];
                $row = (int) $match[2];
                $value = trim($match[3]);

                if (! isset($columns[$column])) {
                    $columns[$column] = [
                        'column' => $column,
                        'header_rows' => [],
                    ];
                }

                $columns[$column]['header_rows'][] = [
                    'row' => $row,
                    'value' => $value,
                ];
            }
        }

        return [
            'text' => implode(PHP_EOL, $contextLines),
            'columns' => $columns,
        ];
    }

    private function createChunks(array $parts): array
    {
        $total = count($parts);
        $chunks = [];

        $headerContext = trim(
            mb_substr(
                $parts[0] ?? '',
                0,
                2000
            )
        );

        foreach ($parts as $i => $content) {
            $pageNumbers = $this->pageNumbers($content);

            $chunks[] = new DocumentChunk(
                index: $i + 1,
                total: $total,
                content: trim($content),
                sourceMetadata: [
                    'type' => 'pdf',
                    'page' => $pageNumbers[0] ?? null,
                    'pages' => $pageNumbers,
                ],
                containsHeader: $i === 0,
                isLast: $i === ($total - 1),
                headerContext: $headerContext
            );
        }

        return $chunks;
    }

    private function pageNumbers(string $content): array
    {
        preg_match_all(
            '/={20,}\R?PAGE\s+(\d+)\R?={20,}/i',
            $content,
            $matches
        );

        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }
}
