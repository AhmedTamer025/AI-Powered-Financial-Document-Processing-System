<?php

namespace App\AI\Services\Financial;

use App\Enums\BankStatementTransaction;
use App\Models\BankStatement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaveBankStatementService
{
    public function execute(
        array $data,
        string $businessId,
        string $aiResultId,
        array $fileInfo
    ): BankStatement {

        Log::info('SaveBankStatementService started', [
            'business_id' => $businessId,
            'ai_result_id' => $aiResultId,
        ]);

        return DB::transaction(function () use (
            $data,
            $businessId,
            $aiResultId,
            $fileInfo
        ) {

            $bankData = $data['bank_statement'] ?? [];

            Log::info('Bank statement extracted data', $bankData);

            /*
            |--------------------------------------------------------------------------
            | Get Existing Statement
            |--------------------------------------------------------------------------
            */

            $statement = BankStatement::query()
                ->where('business_id', $businessId)
                ->where('ai_result_id', $aiResultId)
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Update Statement
            |--------------------------------------------------------------------------
            */

            $statement->update([

                /*
                |----------------------------------------------------------------------
                | File Metadata
                |----------------------------------------------------------------------
                */

                'original_file_name' => $fileInfo['original_file_name'] ?? null,

                'stored_file_name' => $fileInfo['stored_file_name'] ?? null,

                'stored_path' => $fileInfo['stored_path'] ?? null,

                'extension' => $fileInfo['extension'] ?? null,

                'mime_type' => $fileInfo['mime_type'] ?? null,

                'size' => $fileInfo['size'] ?? null,

                /*
                |----------------------------------------------------------------------
                | Bank Data
                |----------------------------------------------------------------------
                */

                'bank_name' => $bankData['bank_name'] ?? null,

                'branch' => $bankData['branch'] ?? null,

                'account_holder' => $bankData['account_holder'] ?? null,

                'account_number' => $bankData['account_number'] ?? null,

                'iban' => $bankData['iban'] ?? null,

                'currency' => $bankData['currency'] ?? null,

                /*
                |----------------------------------------------------------------------
                | Statement Period
                |----------------------------------------------------------------------
                */

                'statement_from' => $this->normalizeDate(
                    $bankData['statement_period']['from'] ?? null
                ),

                'statement_to' => $this->normalizeDate(
                    $bankData['statement_period']['to'] ?? null
                ),

                /*
                |----------------------------------------------------------------------
                | Balances
                |----------------------------------------------------------------------
                */

                'opening_balance' => $bankData['opening_balance'] ?? null,

                'closing_balance' => $bankData['closing_balance'] ?? null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Transactions
            |--------------------------------------------------------------------------
            */

            foreach ($bankData['transactions'] ?? [] as $transaction) {

                $rawType = $transaction['transaction_type']
                    ?? $transaction['type']
                    ?? null;

                /*
                |--------------------------------------------------------------------------
                | Convert AI transaction type to enum
                |--------------------------------------------------------------------------
                */

                $transactionType = BankStatementTransaction::fromLabel(
                    is_string($rawType)
                        ? $rawType
                        : null
                );

                /*
                |--------------------------------------------------------------------------
                | Preserve Unknown Transaction
                |--------------------------------------------------------------------------
                |
                | Normally the AI should return "other".
                |
                | This extra protection handles cases where the AI/provider
                | still returns an unsupported string.
                |
                */

                $otherTransaction = null;

                if (
                    !blank($rawType)
                    && $transactionType === BankStatementTransaction::Other
                    && strtolower(trim((string) $rawType)) !== 'other'
                ) {
                    $otherTransaction = trim((string) $rawType);
                }

                $statement->transactions()->create([

                    /*
                    |------------------------------------------------------------------
                    | Dates
                    |------------------------------------------------------------------
                    */

                    'posting_date' => $this->normalizeDate(
                        $transaction['posting_date'] ?? null
                    ),

                    'value_date' => $this->normalizeDate(
                        $transaction['value_date'] ?? null
                    ),

                    /*
                    |------------------------------------------------------------------
                    | Transaction
                    |------------------------------------------------------------------
                    */

                    'description' => $transaction['description'] ?? null,

                    'reference' => $transaction['reference'] ?? null,

                    'counterparty' => $transaction['counterparty'] ?? null,

                    'category' => $transaction['category'] ?? null,

                    /*
                    | Enum
                    */

                    'type' => $transactionType,

                    /*
                    | Original unsupported type
                    */

                    'other_transaction' => $otherTransaction,

                    /*
                    |------------------------------------------------------------------
                    | Amounts
                    |------------------------------------------------------------------
                    */

                    'debit' => $transaction['debit'] ?? null,

                    'credit' => $transaction['credit'] ?? null,

                    'balance' => $transaction['balance'] ?? null,

                    /*
                    |------------------------------------------------------------------
                    | AI Metadata
                    |------------------------------------------------------------------
                    */

                    'confidence' => $transaction['confidence'] ?? null,

                    'source' => $transaction['source'] ?? null,
                ]);
            }

            return $statement;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Date Normalization
    |--------------------------------------------------------------------------
    */

    private function normalizeDate(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        $date = trim($date);

        $formats = [
            'dMY',
            'd M Y',
            'd M y',
            'd-M-Y',
            'd-M-y',
            'd/m/Y',
            'd/m/y',
            'j/n/Y',
            'j/n/y',
            'j/m/Y',
            'j/m/y',
            'd/n/Y',
            'd/n/y',
            'd-m-Y',
            'd-m-y',
            'j-n-Y',
            'j-n-y',
            'Y-m-d',
            'Y/m/d',
            'd.m.Y',
            'd.m.y',
            'j.n.Y',
            'j.n.y',
        ];

        foreach ($formats as $format) {
            try {

                $parsed = Carbon::createFromFormat(
                    $format,
                    $date
                );

                if ($parsed !== false) {
                    return $parsed->format('Y-m-d');
                }

            } catch (\Throwable $e) {
                // Try next format.
            }
        }

        try {

            return Carbon::parse($date)
                ->format('Y-m-d');

        } catch (\Throwable $e) {

            Log::warning(
                'Unable to normalize bank statement date',
                [
                    'date' => $date,
                ]
            );

            return null;
        }
    }
}
