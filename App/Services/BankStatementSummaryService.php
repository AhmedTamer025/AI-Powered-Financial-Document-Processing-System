<?php

namespace App\Services;

use App\Models\BankStatement;

class BankStatementSummaryService
{
    /**
     * Calculates the summary for a single bank statement
     * based on its transactions.
     */
    public function calculate(BankStatement $statement): array
    {
        $transactions = $statement->transactions;

        $totalDebits = round($transactions->sum('debit'), 2);
        $totalCredits = round($transactions->sum('credit'), 2);

        $openingBalance = $statement->opening_balance;
        $closingBalance = $statement->closing_balance;

        $expectedClosingBalance = $openingBalance !== null
            ? round((float) $openingBalance + $totalCredits - $totalDebits, 2)
            : null;

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'net_change' => round($totalCredits - $totalDebits, 2),
            'transaction_count' => $transactions->count(),
            'expected_closing_balance' => $expectedClosingBalance,
            'is_balanced' => $this->isBalanced(
                $openingBalance,
                $closingBalance,
                $expectedClosingBalance
            ),
        ];
    }

    /**
     * Formats a single transaction for the API response.
     */
    public function formatTransaction($transaction): array
    {
        return [
            'bank_statement_id' => $transaction->bank_statement_id,
            'posting_date' => $transaction->posting_date?->toDateString(),
            'value_date' => $transaction->value_date?->toDateString(),
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'counterparty' => $transaction->counterparty,
            'type' => $transaction->type?->label(),
            'other_transaction' => $transaction->other_transaction,
            'debit' => $transaction->debit,
            'credit' => $transaction->credit,
            'balance' => $transaction->balance,
            'confidence' => $transaction->confidence,
            'source' => $transaction->source,
        ];
    }

    /**
     * Checks whether the bank statement is balanced:
     * opening balance + credits - debits = closing balance.
     *
     * A small tolerance is used to account for rounding differences.
     */
    private function isBalanced(
        ?string $openingBalance,
        ?string $closingBalance,
        ?float $expectedClosingBalance
    ): bool {
        if (
            $openingBalance === null ||
            $closingBalance === null ||
            $expectedClosingBalance === null
        ) {
            return false;
        }

        return abs($expectedClosingBalance - (float) $closingBalance) < 0.01;
    }
}