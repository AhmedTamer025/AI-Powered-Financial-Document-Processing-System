<?php

namespace App\AI\Extractions;

use App\AI\Exceptions\ExtractionException;
use App\AI\DTOS\RawDocument;
use PhpOffice\PhpWord\IOFactory;

class WordExtractionTool
{
    public function extract(string $path): RawDocument
    {
        set_time_limit(300);

        $this->validate($path);

        try {
            $phpWord = IOFactory::load($path);
        } catch (\Throwable $exception) {
            throw new ExtractionException(
                "Unable to read Word document.",
                previous: $exception
            );
        }

        $plainText = '';
        $sections = [];
        $tables = [];

        foreach ($phpWord->getSections() as $sectionIndex => $section) {

            $sectionText = '';
            $sectionTables = [];

            foreach ($section->getElements() as $element) {

                /*
                Paragraph / Text
                */

                if (
                    $element instanceof \PhpOffice\PhpWord\Element\Text
                ) {
                    $sectionText .=
                        $element->getText()
                        . PHP_EOL;

                    continue;
                }


                /*
                Text Run
                */

                if (
                    $element instanceof \PhpOffice\PhpWord\Element\TextRun
                ) {
                    $text = '';

                    foreach ($element->getElements() as $child) {

                        if (
                            $child instanceof \PhpOffice\PhpWord\Element\Text
                        ) {
                            $text .= $child->getText();
                        }
                    }

                    if ($text !== '') {
                        $sectionText .=
                            $text
                            . PHP_EOL;
                    }

                    continue;
                }


                /*
                Table
                */

                if (
                    $element instanceof \PhpOffice\PhpWord\Element\Table
                ) {
                    $table = [];

                    foreach ($element->getRows() as $row) {

                        $rowData = [];

                        foreach ($row->getCells() as $cell) {

                            $cellText = '';

                            foreach ($cell->getElements() as $cellElement) {

                                if (
                                    $cellElement instanceof \PhpOffice\PhpWord\Element\Text
                                ) {
                                    $cellText .=
                                        $cellElement->getText();
                                }

                                elseif (
                                    $cellElement instanceof \PhpOffice\PhpWord\Element\TextRun
                                ) {
                                    foreach (
                                        $cellElement->getElements()
                                        as $child
                                    ) {
                                        if (
                                            $child instanceof \PhpOffice\PhpWord\Element\Text
                                        ) {
                                            $cellText .=
                                                $child->getText();
                                        }
                                    }
                                }
                            }

                            $rowData[] = trim($cellText);
                        }

                        $table[] = $rowData;
                    }

                    $sectionTables[] = $table;

                    $tables[] = $table;

                    /*
                    Add table to plain text
                    */

                    foreach ($table as $row) {

                        $sectionText .=
                            implode(
                                ' | ',
                                $row
                            )
                            . PHP_EOL;
                    }

                    $sectionText .= PHP_EOL;
                }
            }

            if (trim($sectionText) === '') {
                continue;
            }

            $plainText .=
                $sectionText
                . PHP_EOL;

            $sections[] = [

                'section' => $sectionIndex + 1,

                'text' => trim($sectionText),

                'tables' => $sectionTables,

            ];
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

            tables: $tables,

            metadata: [

                'extractor' => self::class,

                'section_count' => count($sections),

                'table_count' => count($tables),

                'created_at' => now()->toISOString(),

            ],

            warnings: [],

        );
    }


    private function validate(string $path): void
    {
        if (! file_exists($path)) {
            throw new ExtractionException(
                "Word file does not exist: {$path}"
            );
        }

        if (! is_readable($path)) {
            throw new ExtractionException(
                "Word file is not readable."
            );
        }

        $extension = strtolower(
            pathinfo($path, PATHINFO_EXTENSION)
        );

        if (! in_array($extension, ['docx', 'doc'])) {
            throw new ExtractionException(
                "Unsupported Word format: {$extension}"
            );
        }

        if (filesize($path) === 0) {
            throw new ExtractionException(
                "Word file is empty."
            );
        }
    }
}