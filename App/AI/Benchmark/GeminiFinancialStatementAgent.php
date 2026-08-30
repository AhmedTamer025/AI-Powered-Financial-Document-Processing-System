<?php

namespace App\AI\Benchmark;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Gemini)]
#[Temperature(0.0)]
#[MaxTokens(16000)]
class GeminiFinancialStatementAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<PROMPT
You are an expert financial statement normalization engine.

The input contains already-extracted data from a financial document.

Your task is to map financial statement line items into the normalized schema.

STRICT RULES:

1. Use ONLY values explicitly present in the extracted input.

2. NEVER invent, estimate, guess, approximate, calculate, sum,
   subtract, derive, or reconstruct values.

3. Preserve the exact numeric value from the source.

4. If a normalized field does not have a clear direct source line item,
   return null.

5. Do not populate a field based only on semantic similarity
   if the mapping is ambiguous.

6. The document may contain multiple reporting periods,
   quarters, and years.

7. NEVER mix values from different reporting periods.

8. Normalize equivalent financial statement line items into the
   correct schema field whenever the accounting meaning is clear.

9. Prefer the most direct explicit financial statement line item
   over supporting schedules.

10. If the correct reporting period cannot be determined clearly,
    return null.

11. Do not use unrelated supporting schedules.

12. Do not calculate totals, subtotals, ratios, profits, losses,
    or changes.

13. Do not infer accounting classifications that are not explicitly
    supported.

14. If a value is missing or ambiguous, return null.

15. Empty fields must be returned as null.

16. Return only structured output matching the schema.

17. Always populate reporting_periods with EVERY identifiable reporting period.

18. For every financial field return an array of:

{
    "period_id": "2022_draft",
    "value": numeric value or null,
    "confidence": number between 0 and 1,
    "source": {
        "type": "pdf|excel|word|image|ocr|unknown",
        "page": 4,
        "sheet": "Income Statement",
        "row": 12,
        "cell": "B12",
        "label": "Revenue"
    }
}

19. Confidence rules:

- Directly extracted value:
  confidence between 0.95 and 1.0

- Clear mapping but less certainty:
  confidence between 0.50 and 0.94

- Missing or unavailable value:
  omit the period value

20. reporting_periods[].date must contain the reporting date exactly
    as shown.

21. reporting_periods[].period_type must be one of:

- annual
- quarterly
- monthly
- half_year
- ytd
- unknown

22. Never select only the latest period.
    Never mix values from different periods.
    Never treat a Difference column as a reporting period
    unless the document explicitly identifies it as one.
    If a period is ambiguous, omit it.

IMPORTANT:

A wrong value is worse than a null value.

When uncertain, return null.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
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

            'reporting_periods' => $schema
                ->array()
                ->items(
                    $schema->object(
                        fn ($schema) => [
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
                        ]
                    )
                )
                ->required(),

            'balance_sheet' => $schema->object(
                fn ($schema) => [
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
                ]
            ),

            'income_statement' => $schema->object(
                fn ($schema) => [
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
                ]
            ),

            'cash_flow_statement' => $schema->object(
                fn ($schema) => [
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
                ]
            ),

            'equity_statement' => $schema->object(
                fn ($schema) => [
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
                ]
            ),
        ];
    }

    private function provenanceField(JsonSchema $schema): mixed
    {
        return $schema->object(
            fn ($schema) => [
                'type' =>
                    $schema->string()->nullable(),

                'page' =>
                    $schema->number()->nullable(),

                'sheet' =>
                    $schema->string()->nullable(),

                'row' =>
                    $schema->number()->nullable(),

                'cell' =>
                    $schema->string()->nullable(),

                'section' =>
                    $schema->number()->nullable(),

                'table' =>
                    $schema->number()->nullable(),

                'block' =>
                    $schema->number()->nullable(),

                'label' =>
                    $schema->string()->nullable(),
            ]
        )->nullable();
    }

    private function confidenceField(JsonSchema $schema): mixed
    {
        return $schema
            ->array()
            ->items(
                $schema->object(
                    fn ($schema) => [
                        'period_id' =>
                            $schema->string()->required(),

                        'value' =>
                            $schema
                                ->number()
                                ->nullable(),

                        'confidence' =>
                            $schema
                                ->number()
                                ->required(),

                        'source' =>
                            $this->provenanceField($schema),
                    ]
                )
            );
    }
}
