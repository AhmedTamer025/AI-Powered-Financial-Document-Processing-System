<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\FinancialAnalysis\FinancialStatementAnalysisService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialStatementAnalysisController extends Controller
{
    public function analyze(
        Request $request,
        string $businessId,
        FinancialStatementAnalysisService $analysis
    ): JsonResponse {
        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                Rule::in([
                    'balance_sheet',
                    'income_statement',
                    'cash_flow_statement',
                    'equity_statement',
                ]),
            ],
            'from' => ['required', 'date', 'before_or_equal:to'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $business = Business::query()->find($businessId);

        if ($business === null) {
            return response()->json([
                'message' => 'Business not found.',
            ], 404);
        }

        $from = Carbon::parse($validated['from'])->toDateString();
        $to = Carbon::parse($validated['to'])->toDateString();

        $result = $analysis->summarize(
            $business->id,
            $from,
            $to,
            $validated['type']
        );

        return response()->json([
            'message' => 'Financial analysis retrieved successfully.',
            ...$result,
        ]);
    }
}
