<?php

namespace App\AI\Services;

use Illuminate\Support\Facades\Log;
use App\AI\DTOS\NormalizedDocument;
use App\AI\DTOS\RawDocument;
use App\Enums\TransactionPeriod;

class GeminDocumentNormalizationService
{
    private array $lastUsage = [];

    public function __construct(
        private DocumentNormalizationRouter $router,
        private DocumentMergeService $mergeService,
    ) {}

    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    public function normalize(
        RawDocument $document,
        string|\App\Enums\DocumentType|null $documentType
    ): NormalizedDocument {

        set_time_limit(0);
        ini_set('max_execution_time', '0');

        Log::info('PHP LIMIT INSIDE NORMALIZER', [
            'max_execution_time' => ini_get('max_execution_time'),
        ]);

        $this->validateDocument($document);

        $documentType = $this->normalizeDocumentType($documentType);

        if ($documentType === 'unsupported') {

            return new NormalizedDocument(
                documentType: 'unsupported',
                overallConfidence: 0,

                warnings: [
                    'The uploaded document is not a supported financial document.'
                ],

                reportingPeriods: [],

                bankStatement: null,
                balanceSheet: null,
                incomeStatement: null,
                cashFlowStatement: null,
                equityStatement: null,
            );
        }



        /*
        |--------------------------------------------------------------------------
        | STEP 3: Normalize With Specific Agent
        |--------------------------------------------------------------------------
        */

        $responses = $this->router->normalize(
            $document,
            $documentType
        );

        $this->lastUsage = $this->router->lastUsage();

        /*
        |--------------------------------------------------------------------------
        | STEP 4: Merge AI Responses
        |--------------------------------------------------------------------------
        */

        $data = $this->mergeService->merge(
            $responses,
            $documentType
        );

        if ($documentType === 'financial_statement') {
            $data = $this->preserveReportingPeriods($data);
        }





        /*
        |--------------------------------------------------------------------------
        | STEP 5: Bank Statement Cleanup
        |--------------------------------------------------------------------------
        */

        if ($documentType === 'bank_statement') {

            $data = $this->cleanAndNormalizeData(
                $data
            );
        }



        return $this->createNormalizedDocument(
            $data
        );
    }



    private function normalizeDocumentType(string|\App\Enums\DocumentType|null $documentType): string
    {
        if ($documentType instanceof \App\Enums\DocumentType) {
            return $documentType->label();
        }

        if (is_string($documentType) && trim($documentType) !== '') {
            return strtolower(trim($documentType));
        }

        return 'unsupported';
    }



    private function cleanAndNormalizeData(array $data): array
    {
        /*
        |--------------------------------------------------------------------------
        | If no bank statement return original data
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['bank_statement'])
            ||
            !is_array($data['bank_statement'])
        ) {

            return $data;
        }



        $bs = &$data['bank_statement'];



        /*
        |--------------------------------------------------------------------------
        | STEP 1: Normalize Single Value Fields
        |--------------------------------------------------------------------------
        */

        $singleValueFields = [

            'bank_name',

            'branch',

            'account_number',

            'iban',

            'currency',

            'account_holder',

        ];



        foreach ($singleValueFields as $field) {

            if (isset($bs[$field])) {

                $bs[$field] =
                    $this->extractFirstValue(
                        $bs[$field]
                    );
            }
        }




        /*
        |--------------------------------------------------------------------------
        | STEP 2: Normalize Amount Fields
        |--------------------------------------------------------------------------
        */

        if (isset($bs['opening_balance'])) {

            $bs['opening_balance'] =
                $this->extractFirstNumber(
                    $bs['opening_balance']
                );
        }



        if (isset($bs['closing_balance'])) {

            $bs['closing_balance'] =
                $this->extractLastNumber(
                    $bs['closing_balance']
                );
        }




        /*
        |--------------------------------------------------------------------------
        | STEP 3: Normalize Statement Period
        |--------------------------------------------------------------------------
        */

        if (
            isset($bs['statement_period'])
            &&
            is_array($bs['statement_period'])
        ) {


            $statementPeriod =
                $bs['statement_period'];



            if (isset($statementPeriod['from'])) {

                $statementPeriod['from'] =
                    $this->extractFirstValue(
                        $statementPeriod['from']
                    );
            }



            if (isset($statementPeriod['to'])) {

                $statementPeriod['to'] =
                    $this->extractFirstValue(
                        $statementPeriod['to']
                    );
            }



            $bs['statement_period'] =
                $statementPeriod;
        }




        /*
        |--------------------------------------------------------------------------
        | STEP 4: Transactions Cleanup
        |--------------------------------------------------------------------------
        */

        if (
            isset($bs['transactions'])
            &&
            is_array($bs['transactions'])
        ) {


            $transactions = [];

            $seen = [];



            foreach ($bs['transactions'] as $transaction) {



                /*
                |--------------------------------------------------------------------------
                | Remove empty transactions
                |--------------------------------------------------------------------------
                */

                if (

                    empty($transaction['posting_date'])

                    &&

                    empty($transaction['description'])

                ) {

                    continue;
                }




                /*
                |--------------------------------------------------------------------------
                | Remove corrupted amounts
                |--------------------------------------------------------------------------
                */

                foreach (

                    [

                        'debit',

                        'credit',

                        'balance'

                    ]

                    as $field

                ) {


                    if (

                        isset($transaction[$field])

                        &&

                        is_numeric(
                            $transaction[$field]
                        )

                        &&

                        abs(
                            $transaction[$field]
                        ) > 10000000000

                    ) {

                        $transaction[$field] = null;
                    }
                }




                /*
                |--------------------------------------------------------------------------
                | Duplicate Key
                |--------------------------------------------------------------------------
                */

                $key = implode('|', [

                    $transaction['posting_date'] ?? '',

                    $transaction['value_date'] ?? '',

                    $transaction['description'] ?? '',

                    $transaction['reference'] ?? '',

                    $transaction['debit'] ?? '',

                    $transaction['credit'] ?? '',

                    $transaction['balance'] ?? '',

                ]);



                if (!isset($seen[$key])) {


                    $seen[$key] = true;


                    $transactions[] =
                        $transaction;
                }
            }



            $bs['transactions'] =
                $transactions;
        }



        return $data;
    }




    /**
     * Extract first usable value
     */
    private function extractFirstValue(
        mixed $value
    ): mixed {


        if (!is_array($value)) {

            return $value;
        }



        foreach ($value as $item) {


            if (

                $item !== null

                &&

                $item !== ''

                &&

                $item !== 'null'

                &&

                $item !== 'NULL'

            ) {

                return $item;
            }
        }



        return null;
    }





    /**
     * Extract first numeric value
     */
    private function extractFirstNumber(
        mixed $value
    ): ?float {


        if (!is_array($value)) {

            return is_numeric($value)

                ?

                (float)$value

                :

                null;
        }



        foreach ($value as $item) {


            if (

                $item !== null

                &&

                is_numeric($item)

            ) {

                return (float)$item;
            }
        }



        return null;
    }





    /**
     * Extract last numeric value
     */
    private function extractLastNumber(
        mixed $value
    ): ?float {


        if (!is_array($value)) {


            return is_numeric($value)

                ?

                (float)$value

                :

                null;
        }



        foreach (

            array_reverse($value)

            as $item

        ) {


            if (

                $item !== null

                &&

                is_numeric($item)

            ) {


                return (float)$item;
            }
        }



        return null;
    }

    /**
     * Validate extracted document
     */
    private function validateDocument(
        RawDocument $document
    ): void {


        if (
            trim($document->plainText) === ''
        ) {

            throw new \RuntimeException(
                'Document contains no extractable text.'
            );
        }
    }

    /**
     * Keep every reporting period. Never collapse back to one period.
     */
    private function preserveReportingPeriods(array $data): array
    {
        $periods = $data['reporting_periods'] ?? null;

        if (! is_array($periods) || $periods === []) {
            $legacy = $data['reporting_period'] ?? null;

            if (is_array($legacy)) {
                $periods = array_is_list($legacy) ? $legacy : [$legacy];
            } else {
                $periods = [];
            }
        }

        $clean = [];

        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $periodId = trim((string) ($period['period_id'] ?? ''));

            if ($periodId === '') {
                continue;
            }

            $clean[$periodId] = [
                'period_id' => $periodId,
                'date' => $period['date'] ?? null,
                'period_type' => TransactionPeriod::fromLabel(
                    $period['period_type'] ?? null
                )->label(),
                'label' => $period['label'] ?? $periodId,
            ];
        }

        $data['reporting_periods'] = array_values($clean);
        unset($data['reporting_period']);

        Log::info('Financial statement reporting periods detected', [
            'count' => count($data['reporting_periods']),
            'period_ids' => array_column($data['reporting_periods'], 'period_id'),
        ]);

        return $data;
    }

    /**
     * Create final normalized DTO
     */
    private function createNormalizedDocument(
        array $data
    ): NormalizedDocument {

        return new NormalizedDocument(

            documentType: $data['document_type']
                ?? 'bank_statement',

            overallConfidence: (float) (
                $data['overall_confidence']
                ?? 0
            ),

            warnings: $data['warnings']
                ?? [],

            reportingPeriods: $data['reporting_periods']
                ?? [],

            bankStatement: $data['bank_statement']
                ?? null,

            balanceSheet: $data['balance_sheet']
                ?? null,

            incomeStatement: $data['income_statement']
                ?? null,

            cashFlowStatement: $data['cash_flow_statement']
                ?? null,

            equityStatement: $data['equity_statement']
                ?? null,
        );
    }
}
