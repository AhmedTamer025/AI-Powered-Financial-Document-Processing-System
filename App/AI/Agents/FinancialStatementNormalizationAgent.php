<?php

namespace App\AI\Agents;

use App\Enums\FinancialStatementCategory;
use App\Enums\FinancialStatementItem;
use App\Enums\TransactionPeriod;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Mistral)]
#[Temperature(0.0)]
#[MaxTokens(16000)]
#[Timeout(300)]
class FinancialStatementNormalizationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        $categories = FinancialStatementCategory::promptValues();

        $items = FinancialStatementItem::promptValues();

        $periods = TransactionPeriod::promptValues();

        return <<<PROMPT
You are an expert financial statement normalization engine.

The input contains already-extracted data from a financial document.

Your task is to map financial statement line items into the normalized schema.

STRICT RULES:

1. Use ONLY values explicitly present in the extracted input.

2. NEVER invent, estimate, guess, approximate, calculate, sum, subtract, derive, or reconstruct values.

3. Preserve the exact numeric value from the source.

4. If a normalized field does not have a clear direct source line item, return null.

5. Do not populate a field based only on semantic similarity if the mapping is ambiguous.

6. The document may contain multiple reporting periods, quarters, and years.

7. NEVER mix values from different reporting periods.

8. Normalize equivalent financial statement line items into the correct schema field whenever the accounting meaning is clear.

9. Prefer the most direct explicit financial statement line item over supporting schedules.

10. If the correct reporting period cannot be determined clearly, return null.

11. Do not use unrelated supporting schedules.

12. Do not calculate totals, subtotals, ratios, profits, losses, or changes.

13. Do not infer accounting classifications that are not explicitly supported.

14. If a value is missing or ambiguous, return null.

15. Empty fields must be returned as null.

16. Return only structured output matching the schema.

17. Always populate reporting_periods with EVERY identifiable reporting period in the document or chunk.

18. Multiple reporting periods may exist in one document. Never select only the latest period. Never silently discard an earlier DISTINCT period that appears in this chunk.

19. Each reporting period MUST include:

- period_id
- date
- period_type
- label

20. period_id must be stable and unique for that period or version. Examples: 2022, 2023, 2022_draft, 2022_audit_final.

21. Different versions or bases of the same year MUST remain separate periods even if the date is identical. Example: "2022 Draft" and "2022 Audit Final".

22. Excel: map each value to the period of its own column. If headers differ (2021, 2022 Draft, Audit 1, Audit Final), D7/E7/F7/G7 are four separate periods. If several columns share the same label such as Q2 2024, that is one period. Never skip Cash & Banks when that row is in the chunk.

23. A "Difference" column is NOT a reporting period unless the document explicitly identifies it as one.

24. Do not invent a period. If a period is ambiguous, omit it rather than guessing.

25. NEVER mix values from different reporting periods. NEVER merge different versions. NEVER calculate a missing period.

26. Each financial metric MUST be an array of period values. Each value MUST include:

- period_id
- value
- confidence
- source

27. Every value must have a period_id. A value without a period_id is invalid.

28. Valid financial statement categories are:

{$categories}

29. Valid financial statement items are:

{$items}

30. Valid reporting period types are:

{$periods}

31. reporting_periods[].date must contain the reporting date exactly as shown in the source, or null if the date is not visible.

32. reporting_periods[].period_type MUST be one of the valid reporting period types. If it cannot be determined, use "unknown".

33. Do not invent a financial statement item.

34. If the source line item does not clearly map to one of the normalized fields, leave that metric as an empty array.

35. If this chunk contains balance sheet lines such as Cash & Banks, Inventory, Receivables, Payables, Equity, or Totals, you MUST populate those metric arrays. Do not skip the balance sheet to save output space.

36. period_id must be lowercase and stable. Use 2021_q1, not 2021_Q1. Use 2022_draft, 2022_audit_1, 2022_audit_final for different 2022 versions.

IMPORTANT:

A wrong value is worse than a null value.

A wrong period is worse than omitting the period.

When uncertain, return null / omit the period.

Return only structured output.

PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Document
            |--------------------------------------------------------------------------
            */

            'document_type' => $schema
                ->string()
                ->required(),

            'overall_confidence' => $schema
                ->number()
                ->required(),

            'warnings' => $schema
                ->array()
                ->items(
                    $schema->string()
                )
                ->required(),

            /*
            |--------------------------------------------------------------------------
            | Reporting Periods
            |--------------------------------------------------------------------------
            */

            'reporting_periods' => $schema
                ->array()
                ->items(
                    $schema->object(fn ($schema) => [

                        'period_id' => $schema
                            ->string()
                            ->required(),

                        'date' => $schema
                            ->string()
                            ->nullable(),

                        'period_type' => $schema
                            ->string()
                            ->nullable(),

                        'label' => $schema
                            ->string()
                            ->nullable(),
                    ])
                )
                ->required(),

            /*
            |--------------------------------------------------------------------------
            | Balance Sheet
            |--------------------------------------------------------------------------
            */

            'balance_sheet' => $schema->object(fn ($schema) => [

                'cash' =>
                    $this->confidenceField($schema),

                'cash_equivalents' =>
                    $this->confidenceField($schema),

                'accounts_receivable' =>
                    $this->confidenceField($schema),

                'inventory' =>
                    $this->confidenceField($schema),

                'prepaid_expenses' =>
                    $this->confidenceField($schema),

                'other_current_assets' =>
                    $this->confidenceField($schema),

                'total_current_assets' =>
                    $this->confidenceField($schema),

                'property_plant_equipment' =>
                    $this->confidenceField($schema),

                'intangible_assets' =>
                    $this->confidenceField($schema),

                'investments' =>
                    $this->confidenceField($schema),

                'other_non_current_assets' =>
                    $this->confidenceField($schema),

                'total_assets' =>
                    $this->confidenceField($schema),

                'accounts_payable' =>
                    $this->confidenceField($schema),

                'short_term_loans' =>
                    $this->confidenceField($schema),

                'current_portion_long_term_debt' =>
                    $this->confidenceField($schema),

                'other_current_liabilities' =>
                    $this->confidenceField($schema),

                'total_current_liabilities' =>
                    $this->confidenceField($schema),

                'long_term_loans' =>
                    $this->confidenceField($schema),

                'other_non_current_liabilities' =>
                    $this->confidenceField($schema),

                'total_liabilities' =>
                    $this->confidenceField($schema),

                'share_capital' =>
                    $this->confidenceField($schema),

                'retained_earnings' =>
                    $this->confidenceField($schema),

                'reserves' =>
                    $this->confidenceField($schema),

                'other_equity' =>
                    $this->confidenceField($schema),

                'total_equity' =>
                    $this->confidenceField($schema),
            ]),

            /*
            |--------------------------------------------------------------------------
            | Income Statement
            |--------------------------------------------------------------------------
            */

            'income_statement' => $schema->object(fn ($schema) => [

                'revenue' =>
                    $this->confidenceField($schema),

                'cost_of_sales' =>
                    $this->confidenceField($schema),

                'gross_profit' =>
                    $this->confidenceField($schema),

                'operating_expenses' =>
                    $this->confidenceField($schema),

                'operating_income' =>
                    $this->confidenceField($schema),

                'finance_cost' =>
                    $this->confidenceField($schema),

                'tax_expense' =>
                    $this->confidenceField($schema),

                'net_profit' =>
                    $this->confidenceField($schema),
            ]),

            /*
            |--------------------------------------------------------------------------
            | Cash Flow Statement
            |--------------------------------------------------------------------------
            */

            'cash_flow_statement' => $schema->object(fn ($schema) => [

                'operating_cash_flow' =>
                    $this->confidenceField($schema),

                'investing_cash_flow' =>
                    $this->confidenceField($schema),

                'financing_cash_flow' =>
                    $this->confidenceField($schema),

                'opening_cash' =>
                    $this->confidenceField($schema),

                'closing_cash' =>
                    $this->confidenceField($schema),

                'net_cash_change' =>
                    $this->confidenceField($schema),
            ]),

            /*
            |--------------------------------------------------------------------------
            | Equity Statement
            |--------------------------------------------------------------------------
            */

            'equity_statement' => $schema->object(fn ($schema) => [

                'opening_equity' =>
                    $this->confidenceField($schema),

                'capital' =>
                    $this->confidenceField($schema),

                'reserves' =>
                    $this->confidenceField($schema),

                'retained_earnings' =>
                    $this->confidenceField($schema),

                'net_profit' =>
                    $this->confidenceField($schema),

                'closing_equity' =>
                    $this->confidenceField($schema),
            ]),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Provenance
    |--------------------------------------------------------------------------
    */

    private function provenanceField(JsonSchema $schema): mixed
    {
        return $schema->object(fn ($schema) => [

            'type' => $schema
                ->string()
                ->nullable(),

            'page' => $schema
                ->number()
                ->nullable(),

            'sheet' => $schema
                ->string()
                ->nullable(),

            'row' => $schema
                ->number()
                ->nullable(),

            'cell' => $schema
                ->string()
                ->nullable(),

            'section' => $schema
                ->number()
                ->nullable(),

            'table' => $schema
                ->number()
                ->nullable(),

            'block' => $schema
                ->number()
                ->nullable(),

            'label' => $schema
                ->string()
                ->nullable(),

        ])->nullable();
    }

    /*
    |--------------------------------------------------------------------------
    | Financial Value
    |--------------------------------------------------------------------------
    */

    private function confidenceField(JsonSchema $schema): mixed
    {
        return $schema
            ->array()
            ->items(
                $schema->object(fn ($schema) => [

                    'period_id' => $schema
                        ->string()
                        ->required(),

                    'value' => $schema
                        ->number()
                        ->nullable(),

                    'confidence' => $schema
                        ->number()
                        ->required(),

                    'source' => $this->provenanceField($schema),
                ])
            );
    }
}