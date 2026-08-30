<?php

namespace App\AI\Agents;

use App\Enums\BankStatementTransaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Mistral)]
#[Temperature(0.0)]
#[MaxTokens(16000)]
class BankStatementNormalizationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        $transactionTypes = BankStatementTransaction::promptValues();

        return <<<PROMPT
You are a bank statement extraction engine.

The input is OCR markdown from a bank statement.

Your job:

1. Extract account information.
2. Extract EVERY transaction row.
3. Do not summarize transactions.

Transaction rules:

- Each table row = one transaction.
- Keep transaction order.
- Do not merge rows.
- Do not remove duplicate-looking rows.
- Do not calculate missing amounts.
- Debit column goes to debit.
- Credit column goes to credit.
- Balance column goes to balance.

Every transaction MUST include the field transaction_type.

Never omit transaction_type.

TRANSACTION TYPE RULES:

1. If the statement contains a transaction type column, use its meaning to classify the transaction.

2. Otherwise classify using the transaction description.

3. The transaction_type MUST be exactly one of the allowed normalized values below.

4. NEVER invent a new transaction type.

5. If the transaction cannot be confidently mapped to one of the allowed values, return "other".

6. If the source contains an unusual or unsupported transaction type, return "other".

7. Do not force an unknown transaction into an existing category.

Allowed normalized transaction types:

{$transactionTypes}

Important:

- "other" is the fallback for unknown or unsupported transaction types.
- Do not return arbitrary values such as "mobile_wallet_payment", "POS_PURCHASE", "ATM_CASH", etc.
- Map them to an allowed value only when the meaning is clear.
- Otherwise return "other".

Never guess beyond what the description reasonably indicates.

For every important extracted value, include provenance metadata.

Use sibling fields such as:

- bank_name_source
- branch_source
- account_number_source
- iban_source
- currency_source
- account_holder_source
- opening_balance_source
- closing_balance_source

And a source object on each transaction.

Use null values rather than inventing coordinates.

For missing values:

return null.

Never return 0 unless the document explicitly contains 0.

Return only JSON.

PROMPT;
    }

    private function provenanceField(JsonSchema $schema): mixed
    {
        return $schema->object(fn ($schema) => [
            'type' => $schema->string()->nullable(),
            'page' => $schema->number()->nullable(),
            'sheet' => $schema->string()->nullable(),
            'row' => $schema->number()->nullable(),
            'cell' => $schema->string()->nullable(),
            'section' => $schema->number()->nullable(),
            'table' => $schema->number()->nullable(),
            'block' => $schema->number()->nullable(),
            'label' => $schema->string()->nullable(),
        ])->nullable();
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

            'bank_statement' => $schema->object(fn ($schema) => [

                'bank_name' => $schema
                    ->string()
                    ->nullable(),

                'bank_name_source' => $this->provenanceField($schema),

                'branch' => $schema
                    ->string()
                    ->nullable(),

                'branch_source' => $this->provenanceField($schema),

                'account_number' => $schema
                    ->string()
                    ->nullable(),

                'account_number_source' => $this->provenanceField($schema),

                'iban' => $schema
                    ->string()
                    ->nullable(),

                'iban_source' => $this->provenanceField($schema),

                'currency' => $schema
                    ->string()
                    ->nullable(),

                'currency_source' => $this->provenanceField($schema),

                'account_holder' => $schema
                    ->string()
                    ->nullable(),

                'account_holder_source' => $this->provenanceField($schema),

                /*
                |--------------------------------------------------------------------------
                | Statement Period
                |--------------------------------------------------------------------------
                */

                'statement_period' => $schema->object(fn ($schema) => [

                    'from' => $schema
                        ->string()
                        ->nullable(),

                    'to' => $schema
                        ->string()
                        ->nullable(),

                ]),

                /*
                |--------------------------------------------------------------------------
                | Balances
                |--------------------------------------------------------------------------
                */

                'opening_balance' => $schema
                    ->number()
                    ->nullable(),

                'opening_balance_source' => $this->provenanceField($schema),

                'closing_balance' => $schema
                    ->number()
                    ->nullable(),

                'closing_balance_source' => $this->provenanceField($schema),

                /*
                |--------------------------------------------------------------------------
                | Transactions
                |--------------------------------------------------------------------------
                */

                'transactions' => $schema
                    ->array()
                    ->items(

                        $schema->object(fn ($schema) => [

                            'posting_date' => $schema
                                ->string()
                                ->nullable(),

                            'value_date' => $schema
                                ->string()
                                ->nullable(),

                            /*
                            |--------------------------------------------------------------------------
                            | IMPORTANT
                            |--------------------------------------------------------------------------
                            |
                            | Must contain only one of the enum labels.
                            |
                            */

                            'transaction_type' => $schema
                                ->string()
                                ->required(),

                            'description' => $schema
                                ->string()
                                ->nullable(),

                            'reference' => $schema
                                ->string()
                                ->nullable(),

                            'counterparty' => $schema
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

                            'source' => $this->provenanceField($schema),

                            'confidence' => $schema
                                ->number()
                                ->nullable(),

                        ])

                    ),

            ]),
        ];
    }
}
