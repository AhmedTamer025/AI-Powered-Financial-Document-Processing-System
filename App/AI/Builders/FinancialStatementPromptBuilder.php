<?php

namespace App\AI\Builders;

use App\AI\Chunking\DocumentChunk;
use App\AI\DTOS\RawDocument;

class FinancialStatementPromptBuilder
{
    public static function build(
        RawDocument $document,
        DocumentChunk $chunk
    ): string {

        $warnings = empty($document->warnings)
            ? 'None'
            : implode(
                PHP_EOL,
                $document->warnings
            );

        $header =
            $chunk->containsHeader
                ? 'YES'
                : 'NO';

        $last =
            $chunk->isLast
                ? 'YES'
                : 'NO';

        $sourceMetadata =
            is_array($chunk->sourceMetadata)
                ? $chunk->sourceMetadata
                : [];

        $sourceType =
            $sourceMetadata['type']
            ?? 'unknown';

        $sheetName =
            $chunk->sheetName
            ?? ($sourceMetadata['sheet'] ?? 'unknown');

        $pageNumber =
            $sourceMetadata['page']
            ?? null;

        $pages =
            $pageNumber !== null
                ? (string) $pageNumber
                : (
                    ! empty($sourceMetadata['pages'])
                        ? implode(
                            ', ',
                            $sourceMetadata['pages']
                        )
                        : 'unknown'
                );

        /*
        |--------------------------------------------------------------------------
        | Excel Period / Column Context
        |--------------------------------------------------------------------------
        */

        $periodContext = self::buildPeriodContext(
            $chunk
        );

        return <<<PROMPT
You are an IFRS financial statement normalization engine.

The document has already been extracted.

Current chunk:

Chunk {$chunk->index} of {$chunk->total}

Contains Header:
{$header}

Last Chunk:
{$last}

Sheet Name:
{$sheetName}

First chunk of this sheet:
{$chunk->isFirstSheetChunk}

Source Type:
{$sourceType}

Pages:
{$pages}

============================================================
FINANCIAL STATEMENT TYPE DETECTION
============================================================

Excel files may contain many worksheets.

Worksheet names are NOT reliable.

A worksheet may be named:

Balance Sheet
Statement of Financial Position
Sheet1
PL Q1
Financials
2024
ABC

Anything.

DO NOT classify the worksheet using its name alone.

Classify it from the actual content.

If the content contains:

Assets
Liabilities
Equity
Current Assets

treat it as a Balance Sheet.

If it contains:

Revenue
Sales
COGS
Gross Profit
Operating Expenses

treat it as an Income Statement.

If it contains:

Operating Activities
Investing Activities
Financing Activities

treat it as a Cash Flow Statement.

If it contains:

Retained Earnings
Capital
Owners Equity

treat it as an Equity Statement.

Ignore worksheets that are:

Aging reports
Trend analysis
Ratios
Forecasts
Budgets
Inventory listings
Trial balance
Dashboards
Notes
Supporting schedules

============================================================
MULTIPLE REPORTING PERIODS
============================================================

The same document may contain multiple reporting periods.

You MUST preserve the reporting periods that appear in THIS chunk.

Do NOT select only the latest period.

Do NOT replace older periods with newer periods.

Do NOT merge different versions of the same date.

Do NOT list periods for columns that are not in this chunk.

For every reporting period identify:

- period_id
- date
- period_type
- label

period_type must be one of:

annual
quarterly
monthly
half_year
ytd
unknown

A period can also have a different VERSION or BASIS.

For example:

2022 As per TFL Draft
2022 As per Audit 1
2022 As per Audit (final)

These represent different period records even though
their dates may be identical.

Example:

{
    "period_id": "2022_draft",
    "date": "31/12/2022",
    "period_type": "annual",
    "label": "2022 As per TFL Draft"
}

Example:

{
    "period_id": "2022_audit_final",
    "date": "31/12/2022",
    "period_type": "annual",
    "label": "2022 As per Audit (final)"
}

============================================================
EXCEL COLUMN / PERIOD MAPPING
============================================================

For Excel files, the reporting period is normally associated
with the column containing the financial value.

Columns that share the SAME period label are ONE period.

Example:

ROW 1: D=Q2 2024 | E=Q2 2024 | F=Q2 2024 | G=Q1 2024
ROW 3: D=30-Jun-24 | E=31-May-24 | F=30-Apr-24 | G=31-Mar-24

This is TWO periods, not four:

- period_id "q2_2024", date "30-Jun-24" (latest date in that group), source cell D
- period_id "q1_2024", date "31-Mar-24", source cell G

Do NOT emit col_D, col_E, and col_F as three periods when they all
say Q2 2024.

Different labels stay different periods: Q2 2024, Q1 2024, Q4 2023, 2022.

If a date row exists, put that date on the period. If several columns
share one label, use the latest date in that group.

Do not merge values from different columns when the columns
represent different periods or versions.

A "Difference" column is NOT a reporting period unless the
document explicitly identifies it as one.

For example:

Difference (Audit 1 - Draft)

must NOT be treated as a reporting period.

Never calculate the difference yourself.

Example:

Cash & Banks
D7 = 2,909,687          → 2021
E7 = 24,768,130.63      → 2022_draft
F7 = 23,610,346         → 2022_audit_1
G7 = 25,609,600         → 2022_audit_final

Those four headers are DIFFERENT periods. Return four cash values
with source cells D7, E7, F7, G7. Never drop this balance sheet
just because the chunk also contains a P&L.

If this chunk contains Cash & Banks / Inventory / equity lines,
those arrays MUST be filled. Empty cash is wrong when the row exists.

Periods may also appear vertically in rows, or in separate
tables. Preserve each independently. Never collapse them into
one period.

============================================================
REPORTING PERIOD RULES
============================================================

Extract ALL periods that are explicitly supported by the
document/chunk.

Do not invent dates.

If a date is not visible, return null for the date.

If a period type cannot be confidently identified,
return "unknown".

Do not use the latest period automatically.

============================================================
VALUE EXTRACTION
============================================================

Use ONLY values explicitly present in the extracted content.

Never:

- invent
- estimate
- guess
- calculate
- sum
- subtract
- derive
- reconstruct

If a value cannot be mapped confidently,
return null.

============================================================
PROVENANCE
============================================================

Every normalized financial value MUST include:

- period_id
- value
- confidence
- source

The source must identify the strongest available location.

For Excel prefer:

- type = excel
- sheet
- row
- cell
- label

For example:

{
    "type": "excel",
    "sheet": "BS 2022 & Q1Q2Q3 2023",
    "row": 7,
    "cell": "E7",
    "label": "Cash & Banks"
}

For PDF/image/OCR prefer:

- type
- page
- row or block
- label

For Word prefer:

- type
- section
- table
- row
- label

Never replace a precise source with a weak generic source.

If the exact source cannot be determined confidently,
return null rather than guessing.

============================================================
CURRENT PERIOD CONTEXT
============================================================

{$periodContext}

============================================================
CURRENT CHUNK CONTENT
============================================================

{$chunk->content}

============================================================
FINAL RULES
============================================================

A wrong value is worse than a null value.

A wrong period is worse than a null period.

A wrong source is worse than a null source.

Always preserve the periods that appear in this chunk.

Return ONLY structured output matching the schema.
PROMPT;
    }

    private static function buildPeriodContext(
        DocumentChunk $chunk
    ): string {

        if (empty($chunk->periodContext)) {
            return 'No explicit Excel period context is available.';
        }

        $lines = [];

        foreach (
            $chunk->periodContext
            as $column => $context
        ) {

            if (! is_array($context)) {
                $lines[] =
                    "{$column} -> {$context}";

                continue;
            }

            $headerRows =
                $context['header_rows']
                ?? [];

            $values = [];

            foreach ($headerRows as $headerRow) {
                $value = trim((string) ($headerRow['value'] ?? ''));

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            $lines[] =
                "{$column}: ".implode(' | ', $values);
        }

        return implode(
            PHP_EOL,
            $lines
        );
    }
}