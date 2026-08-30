<?php

namespace App\Http\Controllers;

use App\Enums\FinancialStatementCategory;
use App\Enums\FinancialStatementItem;
use App\Enums\TransactionPeriod;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialStatementController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                Rule::in(FinancialStatementCategory::labels()),
            ],
            'period' => [
                'required',
                'string',
                Rule::in(TransactionPeriod::labels()),
            ],
            'item' => [
                'sometimes',
                'string',
                Rule::in(FinancialStatementItem::labels()),
            ],
        ]);

        $category = FinancialStatementCategory::fromLabel($validated['type']);

        $statements = $business->financialStatements()
            ->with([
                'items' => function ($query) use ($category, $validated) {
                    $query->where('category', $category)
                        ->where('period_type', $validated['period'])
                        ->when(
                            $validated['item'] ?? null,
                            fn ($query, $item) => $query->where(
                                'name',
                                FinancialStatementItem::fromLabel($item)
                            )
                        )
                        ->orderBy('period_date')
                        ->orderBy('name');
                },
            ])
            ->whereHas('items', function ($query) use ($category, $validated) {
                $query->where('category', $category)
                    ->where('period_type', $validated['period']);
            })
            ->latest('date')
            ->get()
            ->filter(fn ($statement) => $statement->items->isNotEmpty())
            ->values()
            ->map(fn ($statement) => [
                'id' => $statement->id,
                'business_id' => $statement->business_id,
                'type' => $validated['type'],
                'period' => $validated['period'],
                'date' => $statement->date?->toDateString(),
                'items' => $statement->items->map(fn ($item) => [
                    'id' => $item->id,
                    'category' => $item->category?->label(),
                    'name' => $item->name?->label(),
                    'label' => $item->label,
                    'period_key' => $item->period_key,
                    'period_date' => $item->period_date?->toDateString(),
                    'period_type' => $item->period_type,
                    'amount' => $item->amount,
                    'confidence' => $item->confidence,
                ])->values(),
            ]);

        return response()->json([
            'message' => 'Financial statements retrieved successfully.',
            'filters' => [
                'type' => $validated['type'],
                'period' => $validated['period'],
            ],
            'statements' => $statements,
        ]);
    }
}