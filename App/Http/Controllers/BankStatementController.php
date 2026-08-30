<?php

namespace App\Http\Controllers;

use App\Enums\BankStatementTransaction as BankStatementTransactionEnum;
use App\Models\Business;
use App\Models\BankStatementTransaction;
use App\Services\BankStatementSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankStatementController extends Controller
{
    public function __construct(
        private BankStatementSummaryService $summaryService
    ) {}

    public function transactions(string $businessId): JsonResponse
    {
        $business = Business::query()->find($businessId);

        if ($business === null) {
            return response()->json([
                'message' => 'Business not found.',
            ], 404);
        }

        $statements = $business->bankStatements()
            ->with([
                'transactions' => fn ($query) => $query
                    ->orderBy('posting_date')
                    ->orderBy('value_date'),
            ])
            ->get();

        $statementsData = $statements->map(function ($statement) {
            return [
                'bank_statement_id' => $statement->id,
                'bank_name' => $statement->bank_name,
                'account_number' => $statement->account_number,
                'currency' => $statement->currency,
                'statement_from' => $statement->statement_from?->toDateString(),
                'statement_to' => $statement->statement_to?->toDateString(),
                'summary' => $this->summaryService->calculate($statement),
                'transactions' => $statement->transactions
                    ->map(fn ($transaction) => $this->summaryService->formatTransaction($transaction))
                    ->values(),
            ];
        });

        return response()->json([
            'message' => 'Bank transactions retrieved successfully.',
            'business_id' => $business->id,
            'total_statements' => $statements->count(),
            'total_transactions' => $statementsData->sum(fn ($s) => $s['summary']['transaction_count']),
            'statements' => $statementsData->values(),
        ]);
    }
        public function searchTransactions(Request $request, string $businessId): JsonResponse
    {
        $business = Business::query()->find($businessId);

        if ($business === null) {
            return response()->json([
                'message' => 'Business not found.',
            ], 404);
        }

        $validated = $request->validate([
            'type' => 'nullable|string',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ]);

        $typeEnum = isset($validated['type'])
            ? BankStatementTransactionEnum::fromLabel($validated['type'])
            : null;

        $transactions = BankStatementTransaction::query()
            ->whereHas(
                'bankStatement',
                fn ($query) => $query->where('business_id', $business->id)
            )
            ->with('bankStatement:id,bank_name,account_number,currency')
            ->when(
                $typeEnum !== null,
                fn ($query) => $query->where('type', $typeEnum->value)
            )
            ->when(
                isset($validated['date_from']),
                fn ($query) => $query->whereDate('posting_date', '>=', $validated['date_from'])
            )
            ->when(
                isset($validated['date_to']),
                fn ($query) => $query->whereDate('posting_date', '<=', $validated['date_to'])
            )
            ->orderBy('posting_date')
            ->orderBy('value_date')
            ->get();

        $data = $transactions->map(function ($transaction) {
            $formatted = $this->summaryService->formatTransaction($transaction);
            $formatted['bank_name'] = $transaction->bankStatement->bank_name;
            $formatted['account_number'] = $transaction->bankStatement->account_number;
            $formatted['currency'] = $transaction->bankStatement->currency;

            return $formatted;
        });

        return response()->json([
            'message' => 'Transactions retrieved successfully.',
            'business_id' => $business->id,
            'filters' => [
                'type' => $validated['type'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ],
            'total_transactions' => $data->count(),
            'transactions' => $data,
        ]);
    }
}