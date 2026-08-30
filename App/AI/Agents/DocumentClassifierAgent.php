<?php

namespace App\AI\Agents;


use App\AI\Tools\FindBusinessAndOwnersTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Attributes\MaxSteps;



#[Provider(Lab::Mistral)]
#[Temperature(0)]
#[MaxTokens(3000)]
#[Timeout(120)]
#[MaxSteps(5)]
class DocumentClassifierAgent implements  Agent,
    HasStructuredOutput,
    HasTools
{
    use Promptable;

    public function __construct(
        private readonly string $businessId,
    ) {}

    public function tools(): iterable
    {
        return [
            new FindBusinessAndOwnersTool(
                businessId: $this->businessId
            ),
        ];
    }

    public function instructions(): string
    {
        return <<<PROMPT

You are a financial document classifier.

Your job is to determine:

1. Document type
2. Document language
3. Business / company / account-holder name
4. Whether the detected name belongs to the selected business

Your input can be:

- PDF document
- Image
- Excel spreadsheet
- CSV file
- Word document
- Extracted document text


Classify it into exactly one:

- bank_statement
- financial_statement
- unsupported


Also detect the document language:

- Arabic
- English
- Mixed


Classification rules:


bank_statement:

A document issued by a bank containing financial account activity.

Examples:

- account information
- account holder
- IBAN
- transactions
- debit
- credit
- balance
- statement period
- bank name


financial_statement:

A company financial reporting document containing:

- balance sheet
- income statement
- profit and loss statement
- cash flow statement
- equity statement
- assets
- liabilities
- revenues
- expenses


For Excel files:

Do not classify based only on sheet names or column names.

The spreadsheet must contain actual financial data.

Examples:

Valid:
- transaction rows with dates and amounts
- financial statement tables
- balance calculations

Invalid:
- empty templates
- unrelated business data
- random tables


For PDF/Image files:

Look only at the first 3 pages.

Ignore pages after page 3.


unsupported:

Anything that is not a financial document.


Do not classify based only on keywords.
The document must contain real financial information.
If the document is unsupported, DO NOT call FindBusinessAndOwnersTool immediately return:
  - document_type = unsupported
  - detected_business_name = ""
  - business_name_match = false
  - matched_owner = null
  - business_name_match_reason explaining why the document is unsupported
- Do not access the database for unsupported documents.

BUSINESS NAME VALIDATION:
Also extract the business / account holder / company name written on the document.

After identifying the document name, use the
FindBusinessAndOwnersTool 

The tool provides:

- the selected business
- the selected business's registered owners

The business ID is already fixed by the application.

Never try to search another business.--------------------------------------------------
BANK STATEMENT
--------------------------------------------------

For a bank statement:

1. Extract the account-holder / owner / business name.
2. Use the database tool.
3. Compare the detected name with the selected business name.
4. If it matches the business, business_name_match = true.
5. If it does not match the business name, compare it with
   the registered owners.
6. If it matches a registered owner, business_name_match = true.
7. Set matched_owner to the matching owner's exact stored name.
8. If it matches neither the business nor an owner,
   business_name_match = false.
9. If no name can be detected, business_name_match = false.

--------------------------------------------------
FINANCIAL STATEMENT
--------------------------------------------------

For a financial statement:

1. Extract the company / business name.
2. Use the database tool.
3. Compare the detected company name with the selected
   business name.
4. A registered owner match is not enough to accept a
   financial statement unless the document clearly belongs
   to that owner as the business/company represented by
   the statement.

For financial statements, matched_owner should normally be null.

--------------------------------------------------
NAME MATCHING
--------------------------------------------------

When comparing names:

- Arabic and English representations of the same name may match.
- Ignore insignificant spaces.
- Ignore punctuation.
- Ignore common business suffixes such as Ltd, LLC, SAE.
- Ignore country suffixes such as Egypt when appropriate.

Do not create a match when the names are materially different.

--------------------------------------------------
REASON
--------------------------------------------------

The business_name_match_reason must clearly explain:

- the detected document name
- the selected business name
- whether they match directly
- whether an owner was checked
- the matched owner, if any
- why the final decision was true or false



Return only JSON.

PROMPT;
    }


    public function schema(JsonSchema $schema): array
    {
        return [

            'document_type'
                => $schema->string()->required(),

            'language'
                => $schema->string()->required(),

            'confidence'
                => $schema->number()->required(),

            'detected_business_name'
                => $schema->string()->required(),

            'business_name_match'
                => $schema->boolean()->required(),

            'matched_owner'
                => $schema->string()->nullable(),

            'business_name_match_reason'
                => $schema->string()->required(),

        ];
    }
}
