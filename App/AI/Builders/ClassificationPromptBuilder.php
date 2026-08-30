<?php

namespace App\AI\Builders;

use App\AI\DTOS\RawDocument;

class ClassificationPromptBuilder
{
    private const MAX_PREVIEW_CHARS = 8000;

   public static function build(RawDocument $document, ?string $expectedBusinessName = null): string
{
    $content = match ($document->extension) {
        'xlsx', 'xls', 'csv', 'ods' => ExcelContentBuilder::build($document),
        default => OcrContentBuilder::build($document),
    };

    $preview = mb_substr(trim($content), 0, self::MAX_PREVIEW_CHARS);

    $warnings = empty($document->warnings)
        ? 'None'
        : implode(PHP_EOL, $document->warnings);

    return <<<PROMPT
Classify this financial document.

File Name:
{$document->fileName}

Extension:
{$document->extension}

Warnings:
{$warnings}

Document Preview:

{$preview}

Identify the business, company, or account-holder name
visible in the document.

Use the business and owners lookup tool when needed
to validate whether the detected name belongs to the
selected business.
PROMPT;
}
}
