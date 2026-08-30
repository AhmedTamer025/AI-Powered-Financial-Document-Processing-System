<?php

namespace App\Services\FinancialAnalysis;

use App\Enums\FinancialStatementItem as ItemName;
use App\Enums\TransactionPeriod;
use App\Models\FinancialStatementItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FinancialStatementAnalysisService
{
    private const BALANCE_SHEET_COMPONENTS = [
        'cash' => ItemName::Cash,
        'cash_equivalents' => ItemName::CashEquivalents,
        'accounts_receivable' => ItemName::AccountsReceivable,
        'inventory' => ItemName::Inventory,
        'prepaid_expenses' => ItemName::PrepaidExpenses,
        'other_current_assets' => ItemName::OtherCurrentAssets,
        'property_plant_equipment' => ItemName::PropertyPlantEquipment,
        'intangible_assets' => ItemName::IntangibleAssets,
        'investments' => ItemName::Investments,
        'other_non_current_assets' => ItemName::OtherNonCurrentAssets,
        'accounts_payable' => ItemName::AccountsPayable,
        'short_term_loans' => ItemName::ShortTermLoans,
        'current_portion_long_term_debt' => ItemName::CurrentPortionLongTermDebt,
        'other_current_liabilities' => ItemName::OtherCurrentLiabilities,
        'long_term_loans' => ItemName::LongTermLoans,
        'other_non_current_liabilities' => ItemName::OtherNonCurrentLiabilities,
        'share_capital' => ItemName::ShareCapital,
        'retained_earnings' => ItemName::RetainedEarnings,
        'reserves' => ItemName::Reserves,
        'other_equity' => ItemName::OtherEquity,
    ];

    public function __construct(
        private PeriodSelector $periodSelector,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function summarize(
        string $businessId,
        string $from,
        string $to,
        string $type
    ): array {
        $warnings = [];

        $needsOpening = in_array($type, [
            'cash_flow_statement',
            'equity_statement',
        ], true);

        $needsBalanceSheetPeriod = in_array($type, [
            'balance_sheet',
            'cash_flow_statement',
            'equity_statement',
        ], true);

        $needsFlowPeriods = in_array($type, [
            'income_statement',
            'cash_flow_statement',
            'equity_statement',
        ], true);

        $items = $this->loadItemsInRange($businessId, $from, $to);
        $priorItems = $needsOpening
            ? $this->loadPriorPeriodItems($businessId, $from)
            : collect();

        if ($items->isEmpty()) {
            $warnings[] = [
                'code' => 'no_data_in_range',
                'message' => sprintf(
                    'No financial statement items were found for this business between %s and %s.',
                    $from,
                    $to
                ),
            ];
        }

        $allItems = $items->concat($priorItems)->values();
        $itemsByPeriod = $this->indexItems($allItems);

        [$periods, $periodWarnings] = $this->periodsFromItems($allItems);
        $warnings = array_merge($warnings, $periodWarnings);

        [$resolved, $versionWarnings] = $this->periodSelector->resolveVersions($periods);
        $warnings = array_merge($warnings, $versionWarnings);

        $balanceSheetPeriod = $needsBalanceSheetPeriod
            ? $this->periodSelector->selectBalanceSheetPeriod(
                $resolved,
                $from,
                $to
            )
            : null;

        $openingPeriod = $needsOpening
            ? $this->periodSelector->selectOpeningPeriod(
                $resolved,
                $from
            )
            : null;

        $flowPeriods = null;

        if ($needsFlowPeriods) {
            [$flowPeriods, $flowWarnings] = $this->periodSelector->selectFlowPeriods(
                $resolved,
                $from,
                $to
            );

            if ($items->isNotEmpty()) {
                $warnings = array_merge($warnings, $flowWarnings);
            }
        }

        if (
            $needsBalanceSheetPeriod
            && $items->isNotEmpty()
            && $balanceSheetPeriod === null
        ) {
            $warnings[] = [
                'code' => 'no_balance_sheet_period',
                'section' => 'balance_sheet',
                'message' => 'No valid reporting period with an ending date inside the requested range was found for the balance sheet.',
            ];
        }

        $data = match ($type) {
            'balance_sheet' => $this->buildBalanceSheet(
                $balanceSheetPeriod,
                $itemsByPeriod,
                $warnings
            ),
            'income_statement' => $this->buildIncomeStatement(
                $flowPeriods,
                $itemsByPeriod,
                $warnings
            ),
            'cash_flow_statement' => $this->buildCashFlowStatement(
                $flowPeriods,
                $balanceSheetPeriod,
                $openingPeriod,
                $itemsByPeriod,
                $this->sourcedField(
                    $balanceSheetPeriod,
                    $itemsByPeriod,
                    ItemName::Cash
                ),
                $from,
                $warnings
            ),
            'equity_statement' => $this->buildRequestedEquityStatement(
                $flowPeriods,
                $balanceSheetPeriod,
                $openingPeriod,
                $itemsByPeriod,
                $from,
                $warnings
            ),
            default => throw new \InvalidArgumentException(
                "Unsupported financial statement type [{$type}]."
            ),
        };

        return [
            'business_id' => $businessId,
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'data' => $data,
            'warnings' => array_values($warnings),
        ];
    }

    /**
     * Equity reuses the existing income and balance-sheet builders only
     * because closing equity / capital / net_profit depend on them.
     *
     * @param  list<ReportingPeriod>|null  $flowPeriods
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function buildRequestedEquityStatement(
        ?array $flowPeriods,
        ?ReportingPeriod $balanceSheetPeriod,
        ?ReportingPeriod $openingPeriod,
        array $itemsByPeriod,
        string $from,
        array &$warnings
    ): array {
        $incomeStatement = $this->buildIncomeStatement(
            $flowPeriods,
            $itemsByPeriod,
            $warnings
        );

        $balanceSheet = $this->buildBalanceSheet(
            $balanceSheetPeriod,
            $itemsByPeriod,
            $warnings
        );

        return $this->buildEquityStatement(
            $flowPeriods,
            $balanceSheetPeriod,
            $openingPeriod,
            $itemsByPeriod,
            $incomeStatement['net_profit'] ?? null,
            $balanceSheet,
            $from,
            $warnings
        );
    }

    /**
     * @return Collection<int, FinancialStatementItem>
     */
    private function loadItemsInRange(
        string $businessId,
        string $from,
        string $to
    ): Collection {
        return FinancialStatementItem::query()
            ->select('financial_statement_items.*')
            ->join(
                'financial_statements',
                'financial_statements.id',
                '=',
                'financial_statement_items.financial_statement_id'
            )
            ->where('financial_statements.business_id', $businessId)
            ->whereNotNull('financial_statement_items.period_date')
            ->whereDate('financial_statement_items.period_date', '>=', $from)
            ->whereDate('financial_statement_items.period_date', '<=', $to)
            ->get();
    }

    /**
     * Load items from the latest period ending before the requested range.
     * Used only for opening balances.
     *
     * @return Collection<int, FinancialStatementItem>
     */
    private function loadPriorPeriodItems(
        string $businessId,
        string $from
    ): Collection {
        $priorDate = FinancialStatementItem::query()
            ->join(
                'financial_statements',
                'financial_statements.id',
                '=',
                'financial_statement_items.financial_statement_id'
            )
            ->where('financial_statements.business_id', $businessId)
            ->whereNotNull('financial_statement_items.period_date')
            ->whereDate('financial_statement_items.period_date', '<', $from)
            ->max('financial_statement_items.period_date');

        if ($priorDate === null) {
            return collect();
        }

        return FinancialStatementItem::query()
            ->select('financial_statement_items.*')
            ->join(
                'financial_statements',
                'financial_statements.id',
                '=',
                'financial_statement_items.financial_statement_id'
            )
            ->where('financial_statements.business_id', $businessId)
            ->whereDate('financial_statement_items.period_date', $priorDate)
            ->get();
    }

    /**
     * @param  Collection<int, FinancialStatementItem>  $items
     * @return array<string, array<int, FinancialStatementItem>>
     */
    // This function converts a flat collection into a nested lookup array
    private function indexItems(Collection $items): array
    {
        $index = [];

        foreach ($items as $item) {
            $periodKey = $this->periodKeyFor($item);

            if ($periodKey === '' || $item->name === null) {
                continue;
            }

            $name = $item->name->value;
            $existing = $index[$periodKey][$name] ?? null;

            if (
                $existing === null
                || ($this->confidenceOf($item) ?? -1) > ($this->confidenceOf($existing) ?? -1)
            ) {
                $index[$periodKey][$name] = $item;
            }// the function keeps the one with the higher confidence
        }

        return $index;
    }

    /**
     * @param  Collection<int, FinancialStatementItem>  $items
     * @return array{0: list<ReportingPeriod>, 1: list<array<string,string>>}
     */
    // Turn raw financial items into understandable reporting periods.
    private function periodsFromItems(Collection $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $periodKey = $this->periodKeyFor($item);

            if ($periodKey === '' || $item->period_date === null) {
                continue;
            }

            $groups[$periodKey][] = $item;
        }

        $periods = [];
        $warnings = [];

        foreach ($groups as $periodKey => $groupItems) {
            $dates = collect($groupItems)
                ->map(fn (FinancialStatementItem $item) => $item->period_date->toDateString())
                ->unique()
                ->values();

            if ($dates->count() > 1) {
                $warnings[] = [
                    'code' => 'inconsistent_period_dates',
                    'message' => sprintf(
                        'Period %s has conflicting period dates (%s) and was skipped.',
                        $periodKey,
                        $dates->implode(', ')
                    ),
                ];

                continue;
            }

            $first = $groupItems[0];
            $label = $first->metadata['period_label'] ?? $periodKey;
            $periodType = $this->periodSelector->inferPeriodType(
                $this->periodTypeLabel($first),
                $periodKey,
                is_string($label) ? $label : null
            );

            [$startsOn, $endsOn] = $this->periodSelector->coverage(
                $dates[0],
                $periodType
            );

            $confidences = array_values(array_filter(
                array_map(fn (FinancialStatementItem $item) => $this->confidenceOf($item), $groupItems),
                fn ($value) => $value !== null
            ));

            $periods[] = new ReportingPeriod(
                periodKey: $periodKey,
                periodDate: $dates[0],
                periodType: $periodType,
                startsOn: $startsOn,
                endsOn: $endsOn,
                label: is_string($label) ? $label : $periodKey,
                confidence: $confidences === [] ? null : array_sum($confidences) / count($confidences),
            );
        }

        return [$periods, $warnings];
    }

    /**
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function buildBalanceSheet(
        ?ReportingPeriod $period,
        array $itemsByPeriod,
        array &$warnings
    ): array {
        $section = [];

        foreach (self::BALANCE_SHEET_COMPONENTS as $field => $name) {
            $section[$field] = $this->sourcedField($period, $itemsByPeriod, $name);
        }

        $section['total_current_assets'] = $this->totalField(
            $period,
            $itemsByPeriod,
            ItemName::TotalCurrentAssets,
            [
                $section['cash'],
                $section['cash_equivalents'],
                $section['accounts_receivable'],
                $section['inventory'],
                $section['prepaid_expenses'],
                $section['other_current_assets'],
            ],
            'cash + cash_equivalents + accounts_receivable + inventory + prepaid_expenses + other_current_assets',
            'total_current_assets',
            $warnings
        );

        $section['total_assets'] = $this->totalField(
            $period,
            $itemsByPeriod,
            ItemName::TotalAssets,
            [
                $section['total_current_assets'],
                $section['property_plant_equipment'],
                $section['intangible_assets'],
                $section['investments'],
                $section['other_non_current_assets'],
            ],
            'total_current_assets + property_plant_equipment + intangible_assets + investments + other_non_current_assets',
            'total_assets',
            $warnings
        );

        $section['total_current_liabilities'] = $this->totalField(
            $period,
            $itemsByPeriod,
            ItemName::TotalCurrentLiabilities,
            [
                $section['accounts_payable'],
                $section['short_term_loans'],
                $section['current_portion_long_term_debt'],
                $section['other_current_liabilities'],
            ],
            'accounts_payable + short_term_loans + current_portion_long_term_debt + other_current_liabilities',
            'total_current_liabilities',
            $warnings
        );

        $section['total_liabilities'] = $this->totalField(
            $period,
            $itemsByPeriod,
            ItemName::TotalLiabilities,
            [
                $section['total_current_liabilities'],
                $section['long_term_loans'],
                $section['other_non_current_liabilities'],
            ],
            'total_current_liabilities + long_term_loans + other_non_current_liabilities',
            'total_liabilities',
            $warnings
        );

        $section['total_equity'] = $this->totalField(
            $period,
            $itemsByPeriod,
            ItemName::TotalEquity,
            [
                $section['share_capital'],
                $section['retained_earnings'],
                $section['reserves'],
                $section['other_equity'],
            ],
            'share_capital + retained_earnings + reserves + other_equity',
            'total_equity',
            $warnings
        );

        return $section;
    }

    /**
     * @param  list<ReportingPeriod>|null  $flowPeriods
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function buildIncomeStatement(
        ?array $flowPeriods,
        array $itemsByPeriod,
        array &$warnings
    ): array {
        $revenue = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::Revenue, 'revenue', $warnings);
        $costOfSales = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::CostOfSales, 'cost_of_sales', $warnings);
        $operatingExpenses = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::OperatingExpenses, 'operating_expenses', $warnings);
        $financeCost = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::FinanceCost, 'finance_cost', $warnings);
        $taxExpense = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::TaxExpense, 'tax_expense', $warnings);

        $grossProfit = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::GrossProfit, 'gross_profit', $warnings, false)
            ?? $this->subtract($revenue, $costOfSales, 'revenue - cost_of_sales');

        $operatingIncome = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::OperatingIncome, 'operating_income', $warnings, false)
            ?? $this->subtract($grossProfit, $operatingExpenses, 'gross_profit - operating_expenses');

        $netProfit = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::NetProfit, 'net_profit', $warnings, false)
            ?? $this->subtractMany(
                $operatingIncome,
                [$financeCost, $taxExpense],
                'operating_income - finance_cost - tax_expense'
            );

        return [
            'revenue' => $revenue,
            'cost_of_sales' => $costOfSales,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'operating_income' => $operatingIncome,
            'finance_cost' => $financeCost,
            'tax_expense' => $taxExpense,
            'net_profit' => $netProfit,
        ];
    }

    /**
     * @param  list<ReportingPeriod>|null  $flowPeriods
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function buildCashFlowStatement(
        ?array $flowPeriods,
        ?ReportingPeriod $balanceSheetPeriod,
        ?ReportingPeriod $openingPeriod,
        array $itemsByPeriod,
        ?array $balanceSheetCash,
        string $from,
        array &$warnings
    ): array {
        $operating = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::OperatingCashFlow, 'operating_cash_flow', $warnings);
        $investing = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::InvestingCashFlow, 'investing_cash_flow', $warnings);
        $financing = $this->aggregateField($flowPeriods, $itemsByPeriod, ItemName::FinancingCashFlow, 'financing_cash_flow', $warnings);

        $openingCash = $this->openingBalance(
            $flowPeriods,
            $openingPeriod,
            $itemsByPeriod,
            ItemName::OpeningCash,
            [ItemName::Cash, ItemName::ClosingCash],
            $from,
            'opening_cash',
            $warnings
        );

        $closingCash = $this->closingBalance(
            $flowPeriods,
            $balanceSheetPeriod,
            $itemsByPeriod,
            ItemName::ClosingCash,
            $balanceSheetCash,
            ItemName::Cash
        );

        $netCashChange = $this->netCashChange(
            $openingCash,
            $closingCash,
            $operating,
            $investing,
            $financing,
            $warnings
        );

        return [
            'operating_cash_flow' => $operating,
            'investing_cash_flow' => $investing,
            'financing_cash_flow' => $financing,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'net_cash_change' => $netCashChange,
        ];
    }

    /**
     * @param  list<ReportingPeriod>|null  $flowPeriods
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  array<string, mixed>  $balanceSheet
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function buildEquityStatement(
        ?array $flowPeriods,
        ?ReportingPeriod $balanceSheetPeriod,
        ?ReportingPeriod $openingPeriod,
        array $itemsByPeriod,
        ?array $netProfit,
        array $balanceSheet,
        string $from,
        array &$warnings
    ): array {
        $openingEquity = $this->openingBalance(
            $flowPeriods,
            $openingPeriod,
            $itemsByPeriod,
            ItemName::OpeningEquity,
            [ItemName::TotalEquity, ItemName::ClosingEquity],
            $from,
            'opening_equity',
            $warnings
        );

        $closingEquity = $this->closingBalance(
            $flowPeriods,
            $balanceSheetPeriod,
            $itemsByPeriod,
            ItemName::ClosingEquity,
            $balanceSheet['total_equity'] ?? null,
            ItemName::TotalEquity
        );

        $capital = $this->sourcedField($balanceSheetPeriod, $itemsByPeriod, ItemName::Capital)
            ?? ($balanceSheet['share_capital'] ?? null);

        $reserves = $this->sourcedField($balanceSheetPeriod, $itemsByPeriod, ItemName::Reserves)
            ?? ($balanceSheet['reserves'] ?? null);

        $retainedEarnings = $this->sourcedField($balanceSheetPeriod, $itemsByPeriod, ItemName::RetainedEarnings)
            ?? ($balanceSheet['retained_earnings'] ?? null);

        return [
            'opening_equity' => $openingEquity,
            'capital' => $capital,
            'reserves' => $reserves,
            'retained_earnings' => $retainedEarnings,
            'net_profit' => $netProfit,
            'closing_equity' => $closingEquity,
        ];
    }

    /**
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     */
    private function sourcedField(
        ?ReportingPeriod $period,
        array $itemsByPeriod,
        ItemName $name
    ): ?array {
        if ($period === null) {
            return null;
        }

        $item = $this->findItem($itemsByPeriod, $period->periodKey, $name);

        if ($item === null || $this->amount($item) === null) {
            return null;
        }

        return $this->sourcedValue($item, $period);
    }

    /**
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  list<?array<string,mixed>>  $componentValues
     * @param  list<array<string,mixed>>  $warnings
     */
    private function totalField(
        ?ReportingPeriod $period,
        array $itemsByPeriod,
        ItemName $explicit,
        array $componentValues,
        string $calculation,
        string $field,
        array &$warnings
    ): ?array {
        $sourced = $this->sourcedField($period, $itemsByPeriod, $explicit);

        if ($sourced !== null) {
            return $sourced;
        }

        if ($period === null) {
            return null;
        }

        foreach ($componentValues as $component) {
            if ($component === null || ! isset($component['value']) || ! is_numeric($component['value'])) {
                $warnings[] = [
                    'code' => 'missing_components',
                    'field' => $field,
                    'section' => 'balance_sheet',
                    'message' => sprintf(
                        'Cannot calculate %s for period %s because one or more required components are missing. Components must belong to the same reporting period.',
                        $field,
                        $period->periodKey
                    ),
                ];

                return null;
            }
        }

        $value = 0.0;
        $sources = [];
        $confidences = [];

        foreach ($componentValues as $component) {
            $value += (float) $component['value'];

            if (isset($component['source'])) {
                $sources[] = $component['source'];
            }

            if (isset($component['sources']) && is_array($component['sources'])) {
                foreach ($component['sources'] as $source) {
                    $sources[] = $source;
                }
            }

            if (isset($component['confidence']) && is_numeric($component['confidence'])) {
                $confidences[] = (float) $component['confidence'];
            }
        }

        return [
            'value' => round($value, 2),
            'calculation' => $calculation,
            'period_date' => $period->periodDate,
            'period_key' => $period->periodKey,
            'confidence' => $confidences === [] ? null : min($confidences),
            'sources' => array_values(array_filter($sources)),
        ];
    }

    /**
     * @param  list<ReportingPeriod>|null  $flowPeriods
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  list<array<string,mixed>>  $warnings
     */
    private function aggregateField(
        ?array $flowPeriods,
        array $itemsByPeriod,
        ItemName $name,
        string $field,
        array &$warnings,
        bool $warnOnMissing = true
    ): ?array {
        if ($flowPeriods === null || $flowPeriods === []) {
            return null;
        }

        $used = [];
        $items = [];

        foreach ($flowPeriods as $period) {
            $item = $this->findItem($itemsByPeriod, $period->periodKey, $name);

            if ($item === null || $this->amount($item) === null) {
                if ($warnOnMissing) {
                    $warnings[] = [
                        'code' => 'missing_period_value',
                        'field' => $field,
                        'message' => sprintf(
                            'Cannot aggregate %s because period %s has no stored value.',
                            $field,
                            $period->periodKey
                        ),
                    ];
                }

                return null;
            }

            $used[] = $period->periodKey;
            $items[] = $item;
        }

        if (count($items) === 1) {
            return $this->sourcedValue($items[0], $flowPeriods[0]);
        }

        $sum = 0.0;
        $sources = [];
        $confidences = [];

        foreach ($items as $item) {
            $sum += $this->amount($item);
            $source = $this->source($item);

            if ($source !== null) {
                $sources[] = $source;
            }

            $confidence = $this->confidenceOf($item);

            if ($confidence !== null) {
                $confidences[] = $confidence;
            }
        }

        return [
            'value' => round($sum, 2),
            'calculation' => 'aggregated_non_overlapping_periods',
            'confidence' => $confidences === [] ? null : min($confidences),
            'periods_used' => $used,
            'sources' => $sources,
        ];
    }

    /**
     * @param  list<ReportingPeriod>|null  $flowPeriods
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     * @param  list<ItemName>  $fallbackNames
     * @param  list<array<string,mixed>>  $warnings
     */
    private function openingBalance(
        ?array $flowPeriods,
        ?ReportingPeriod $openingPeriod,
        array $itemsByPeriod,
        ItemName $explicitName,
        array $fallbackNames,
        string $from,
        string $field,
        array &$warnings
    ): ?array {
        $earliestFlow = $this->earliestPeriod($flowPeriods);

        if ($earliestFlow !== null) {
            $explicit = $this->sourcedField($earliestFlow, $itemsByPeriod, $explicitName);

            if ($explicit !== null) {
                return $explicit;
            }
        }

        if ($openingPeriod === null) {
            return null;
        }

        foreach ($fallbackNames as $fallbackName) {
            $fallback = $this->sourcedField($openingPeriod, $itemsByPeriod, $fallbackName);

            if ($fallback !== null) {
                $expectedPriorDate = Carbon::parse($from)->subDay()->toDateString();

                if ($openingPeriod->periodDate !== $expectedPriorDate) {
                    $warnings[] = [
                        'code' => 'opening_balance_date_mismatch',
                        'field' => $field,
                        'message' => sprintf(
                            '%s was taken from period %s ending %s rather than the day before the requested start date (%s).',
                            $field,
                            $openingPeriod->periodKey,
                            $openingPeriod->periodDate,
                            $expectedPriorDate
                        ),
                    ];
                }

                return $fallback;
            }
        }

        return null;
    }

    /**
     * @param  list<ReportingPeriod>|null  $flowPeriods
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     */
    private function closingBalance(
        ?array $flowPeriods,
        ?ReportingPeriod $balanceSheetPeriod,
        array $itemsByPeriod,
        ItemName $explicitName,
        ?array $balanceSheetFallback,
        ItemName $balanceSheetName
    ): ?array {
        $latestFlow = $this->latestPeriod($flowPeriods);

        if ($latestFlow !== null) {
            $explicit = $this->sourcedField($latestFlow, $itemsByPeriod, $explicitName);

            if ($explicit !== null) {
                return $explicit;
            }
        }

        if ($balanceSheetFallback !== null) {
            return $balanceSheetFallback;
        }

        return $this->sourcedField($balanceSheetPeriod, $itemsByPeriod, $balanceSheetName);
    }

    /**
     * @param  list<array<string,mixed>>  $warnings
     */
    private function netCashChange(
        ?array $openingCash,
        ?array $closingCash,
        ?array $operating,
        ?array $investing,
        ?array $financing,
        array &$warnings
    ): ?array {
        $fromBalances = null;

        if (
            $openingCash !== null
            && $closingCash !== null
            && isset($openingCash['value'], $closingCash['value'])
            && is_numeric($openingCash['value'])
            && is_numeric($closingCash['value'])
        ) {
            $fromBalances = round(
                (float) $closingCash['value'] - (float) $openingCash['value'],
                2
            );
        }

        $fromFlows = null;

        if (
            $operating !== null
            && $investing !== null
            && $financing !== null
            && is_numeric($operating['value'] ?? null)
            && is_numeric($investing['value'] ?? null)
            && is_numeric($financing['value'] ?? null)
        ) {
            $fromFlows = round(
                (float) $operating['value']
                + (float) $investing['value']
                + (float) $financing['value'],
                2
            );
        }

        if ($fromBalances !== null && $fromFlows !== null && abs($fromBalances - $fromFlows) >= 0.015) {
            $warnings[] = [
                'code' => 'net_cash_change_inconsistent',
                'field' => 'net_cash_change',
                'section' => 'cash_flow_statement',
                'message' => sprintf(
                    'Net cash change is inconsistent: closing_cash - opening_cash = %s, but operating_cash_flow + investing_cash_flow + financing_cash_flow = %s.',
                    $fromBalances,
                    $fromFlows
                ),
            ];

            return null;
        }

        if ($fromBalances !== null) {
            return $this->calculatedFromValues(
                $fromBalances,
                'closing_cash - opening_cash',
                array_filter([$openingCash, $closingCash])
            );
        }

        if ($fromFlows !== null) {
            return $this->calculatedFromValues(
                $fromFlows,
                'operating_cash_flow + investing_cash_flow + financing_cash_flow',
                array_filter([$operating, $investing, $financing])
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $left
     * @param  array<string, mixed>|null  $right
     */
    private function subtract(?array $left, ?array $right, string $calculation): ?array
    {
        if (
            $left === null
            || $right === null
            || ! is_numeric($left['value'] ?? null)
            || ! is_numeric($right['value'] ?? null)
        ) {
            return null;
        }

        return $this->calculatedFromValues(
            round((float) $left['value'] - (float) $right['value'], 2),
            $calculation,
            [$left, $right]
        );
    }

    /**
     * @param  array<string, mixed>|null  $base
     * @param  list<?array<string,mixed>>  $subtrahends
     */
    private function subtractMany(?array $base, array $subtrahends, string $calculation): ?array
    {
        if ($base === null || ! is_numeric($base['value'] ?? null)) {
            return null;
        }

        $inputs = [$base];
        $value = (float) $base['value'];

        foreach ($subtrahends as $subtrahend) {
            if ($subtrahend === null || ! is_numeric($subtrahend['value'] ?? null)) {
                return null;
            }

            $value -= (float) $subtrahend['value'];
            $inputs[] = $subtrahend;
        }

        return $this->calculatedFromValues(round($value, 2), $calculation, $inputs);
    }

    /**
     * @param  list<array<string,mixed>>  $inputs
     * @return array<string, mixed>
     */
    private function calculatedFromValues(
        float $value,
        string $calculation,
        array $inputs
    ): array {
        $sources = [];
        $confidences = [];
        $periodsUsed = [];

        foreach ($inputs as $input) {
            if (isset($input['source'])) {
                $sources[] = $input['source'];
            }

            if (isset($input['sources']) && is_array($input['sources'])) {
                foreach ($input['sources'] as $source) {
                    $sources[] = $source;
                }
            }

            if (isset($input['confidence']) && is_numeric($input['confidence'])) {
                $confidences[] = (float) $input['confidence'];
            }

            if (isset($input['period_key']) && is_string($input['period_key'])) {
                $periodsUsed[] = $input['period_key'];
            }

            if (isset($input['periods_used']) && is_array($input['periods_used'])) {
                foreach ($input['periods_used'] as $periodKey) {
                    $periodsUsed[] = $periodKey;
                }
            }
        }

        $result = [
            'value' => $value,
            'calculation' => $calculation,
            'confidence' => $confidences === [] ? null : min($confidences),
            'sources' => array_values(array_filter($sources)),
        ];

        $periodsUsed = array_values(array_unique($periodsUsed));

        if ($periodsUsed !== []) {
            $result['periods_used'] = $periodsUsed;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcedValue(
        FinancialStatementItem $item,
        ReportingPeriod $period
    ): array {
        return [
            'value' => $this->amount($item),
            'period_date' => $period->periodDate,
            'period_key' => $period->periodKey,
            'confidence' => $this->confidenceOf($item),
            'source' => $this->source($item),
        ];
    }

    /**
     * @param  array<string, array<int, FinancialStatementItem>>  $itemsByPeriod
     */
    private function findItem(
        array $itemsByPeriod,
        string $periodKey,
        ItemName $name
    ): ?FinancialStatementItem {
        return $itemsByPeriod[$periodKey][$name->value] ?? null;
    }

    /**
     * @param  list<ReportingPeriod>|null  $periods
     */
    private function earliestPeriod(?array $periods): ?ReportingPeriod
    {
        if ($periods === null || $periods === []) {
            return null;
        }

        usort($periods, fn (ReportingPeriod $a, ReportingPeriod $b) => $a->startsOn <=> $b->startsOn);

        return $periods[0];
    }

    /**
     * @param  list<ReportingPeriod>|null  $periods
     */
    private function latestPeriod(?array $periods): ?ReportingPeriod
    {
        if ($periods === null || $periods === []) {
            return null;
        }

        usort($periods, fn (ReportingPeriod $a, ReportingPeriod $b) => $b->endsOn <=> $a->endsOn);

        return $periods[0];
    }

    private function periodKeyFor(FinancialStatementItem $item): string
    {
        $key = trim((string) $item->period_key);

        if ($key !== '') {
            return $key;
        }

        return $item->period_date?->toDateString() ?? '';
    }

    private function periodTypeLabel(FinancialStatementItem $item): ?string
    {
        $value = $item->period_type;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $enum = TransactionPeriod::tryFrom((int) $value);

            return $enum?->label();
        }

        return (string) $value;
    }

    private function amount(?FinancialStatementItem $item): ?float
    {
        if ($item === null || $item->amount === null || ! is_numeric($item->amount)) {
            return null;
        }

        return round((float) $item->amount, 2);
    }

    private function confidenceOf(?FinancialStatementItem $item): ?float
    {
        if ($item === null || $item->confidence === null || ! is_numeric($item->confidence)) {
            return null;
        }

        return (float) $item->confidence;
    }

    private function source(FinancialStatementItem $item): mixed
    {
        $metadata = $item->metadata ?? [];
        $source = $metadata['provenance'] ?? null;

        return is_array($source) ? $source : null;
    }
}
