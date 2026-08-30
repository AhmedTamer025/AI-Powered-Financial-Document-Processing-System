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
class GeminiBankStatementAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<PROMPT
You are an expert bank statement normalization engine.

The input contains already-extracted data from a bank statement.

Your task is to map the extracted data into the normalized bank statement schema.

STRICT RULES:

1. Use ONLY values explicitly present in the extracted input.

2. NEVER invent, estimate, guess, approximate, calculate, sum,
   subtract, derive, or reconstruct values.

3. Preserve the exact numeric value from the source.

4. If a normalized field does not have a clear direct source,
   return null.

5. Do not populate a field based only on ambiguous semantic similarity.

6. NEVER mix values from different accounts or reporting periods.

7. Do not calculate balances.

8. Do not calculate transaction amounts.

9. Do not infer missing transaction information.

10. Preserve dates exactly as they appear in the source.

11. If a value is missing or ambiguous, return null.

12. Empty fields must be returned as null.

13. Return only structured output matching the schema.

14. A wrong value is worse than a null value.

15. When uncertain, return null.

For every transaction preserve the explicitly extracted values
without modification.
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

            'account_number' => $schema
                ->string()
                ->nullable(),

            'account_name' => $schema
                ->string()
                ->nullable(),

            'bank_name' => $schema
                ->string()
                ->nullable(),

            'currency' => $schema
                ->string()
                ->nullable(),

            'opening_balance' => $schema
                ->number()
                ->nullable(),

            'closing_balance' => $schema
                ->number()
                ->nullable(),

            'transactions' => $schema
                ->array()
                ->items(
                    $schema->object(fn ($schema) => [

                        'date' => $schema
                            ->string()
                            ->nullable(),

                        'description' => $schema
                            ->string()
                            ->nullable(),

                        'debit' => $schema
                            ->number()
                            ->nullable(),

                        'credit' => $schema
                            ->number()
                            ->nullable(),

                        'balance' => $schema
                            ->number()
                            ->nullable(),

                    ])
                )
                ->required(),
        ];
    }
}
