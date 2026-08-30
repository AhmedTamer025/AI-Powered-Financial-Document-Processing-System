<?php

namespace App\AI\Builders;

use App\AI\DTOS\RawDocument;

class ExcelContentBuilder
{
    public static function build(RawDocument $document): string
    {
        $content = '';

        foreach ($document->sections as $sheet) {
            $content .= PHP_EOL;
            $content .= str_repeat('=', 10) . PHP_EOL;
            $content .= "SHEET: {$sheet['name']}" . PHP_EOL;
            $content .= str_repeat('=', 10) . PHP_EOL;

            foreach ($sheet['table'] ?? [] as $row) {
                $rowCells = [];

                foreach ($row['cells'] ?? [] as $cell) {
                    $value = self::normalizeCellValue($cell['value'] ?? '');

                    if ($value === '') {
                        continue;
                    }

                    $rowCells[] = ($cell['address'] ?? 'UNKNOWN') . '=' . $value;
                }

                if (empty($rowCells)) {
                    continue;
                }

                $content .= 'ROW ' . ($row['row'] ?? 'unknown') . ': ' . implode(' | ', $rowCells) . PHP_EOL;
            }
        }

        return trim($content);
    }

    private static function normalizeCellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;
        $value = preg_replace('/\r\n|\r|\n/', ' ', $value) ?? $value;
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
