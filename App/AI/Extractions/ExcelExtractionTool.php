<?php

namespace App\AI\Extractions;

use App\AI\Exceptions\ExtractionException;
use App\AI\DTOS\RawDocument;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelExtractionTool
{
    public function extract(string $path): RawDocument
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');
        $this->validate($path);

        $spreadsheet = $this->loadSpreadsheet($path);

        $sections = $this->extractSheets($spreadsheet);

        $spreadsheet->disconnectWorksheets();

        unset($spreadsheet);
        return $this->buildDocument(
            path: $path,
            sections: $sections
        );
    }
    public function validate(string $path): void
    {
        if (! file_exists($path)) {
            throw new ExtractionException(
                "Excel file does not exist: {$path}"
            );
        }
        if (! is_readable($path)) {
            throw new ExtractionException(
                "Excel file is not readable."
            );
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['xls', 'xlsx', 'csv', 'ods'])) {
            throw new ExtractionException(
                "Unsupported Excel format: {$extension}"
            );
        }

        if (filesize($path) === 0) {
            throw new ExtractionException(
                "Excel file is empty."
            );
        }
    }
    public function loadSpreadsheet(string $path): Spreadsheet
    {
        try {
            return IOFactory::load($path); // Load the spreadsheet using PhpSpreadsheet Detect Format Choose Correct Reader for the file
        } catch (\Throwable $exception) {
            throw new ExtractionException(
                "Unable to read Excel file.",
                previous: $exception
            );
        }
    }


    private function extractSheets(Spreadsheet $spreadsheet): array
    {
        $sections = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) //getWorksheetIterator make an iterator for all the worksheets in the spreadsheet mean that we can loop through each worksheet in the spreadsheet and extract the data from it
        {

            $table = $this->extractTable($worksheet); //return an array of rows and columns representing the data in the worksheet each row is an array of cell values, and each column is represented by the index of the cell in the row array

            if (empty($table)) {
                continue;
            }

            $sections[] = [
                'name' => $worksheet->getTitle(),
                'rows' => count($table),
                'columns' => max(array_map(
                    fn($row) => count($row['cells'] ?? []),
                    $table
                )),
                'table' => $table,
            ];
        }

        return $sections;
    }


    private function extractTable(Worksheet $worksheet): array
    {

        $table = [];

        foreach ($worksheet->getRowIterator() as $row) {

            $rowIndex = $row->getRowIndex();
            $cells = [];

            $cellIterator = $row->getCellIterator(); //iterator is an object that allows you to loop through a collection of items, in this case, the cells in a row of the worksheet. The getCellIterator() method returns an iterator that can be used to loop through all the cells in the current row.

            $cellIterator->setIterateOnlyExistingCells(false); // This loops through all cells, even if they are empty

            foreach ($cellIterator as $cell) {

                $cellAddress = $cell->getCoordinate();
                $column = $cell->getColumn();

                try {

                    $value = $cell->getCalculatedValue();//If the cell contains a formula, this method will return the result of that formula. If the cell does not contain a formula, it will return the value of the cell as-is. =SUM(B2:B10)
                } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $e) {

                    // Return the cached value stored in Excel
                    $value = $cell->getOldCalculatedValue();

                    // If there isn't one, return the formula itself
                    if ($value === null) {
                        $value = $cell->getValue();
                    }
                }

                $cells[] = [
                    'address' => $cellAddress,
                    'value' => $this->normalizeCellValue($value),
                ];
            }

            if ($this->isRowEmpty($cells)) {
                continue;
            }

            $table[] = [
                'row' => $rowIndex,
                'cells' => $cells,
            ];
        }

        return $table;
    }
    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (($cell['value'] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }
    private function normalizeCellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return trim((string) $value); // remove spacing 
    }



    private function buildDocument(string $path, array $sections): RawDocument
    {
        $plainText = '';

        foreach ($sections as $section) {

            $plainText .= "===== {$section['name']} =====" . PHP_EOL;

            foreach ($section['table'] as $row) {
                $plainText .= 'ROW ' . ($row['row'] ?? 'unknown') . PHP_EOL;
                foreach ($row['cells'] ?? [] as $cell) {
                    $plainText .= ($cell['address'] ?? 'UNKNOWN') . ': ' . ($cell['value'] ?? '') . PHP_EOL;
                }
                $plainText .= PHP_EOL;
            }

            $plainText .= PHP_EOL;
        }

        return new RawDocument(

            fileName: basename($path),

            extension: strtolower(
                pathinfo($path, PATHINFO_EXTENSION)
            ),



            filePath: realpath($path) ?: $path,

            fileSize: filesize($path),

            plainText: trim($plainText),

            sections: $sections,

            tables: array_map(
                fn($section) => $section['table'],
                $sections
            ),

            metadata: [

                'extractor' => self::class,

                'sheet_count' => count($sections),

                'total_rows' => array_sum(
                    array_column($sections, 'rows')
                ),

                'created_at' => now()->toISOString(),

            ],

            warnings: [],

        );
    }
}
