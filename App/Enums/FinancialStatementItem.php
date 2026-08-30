<?php

namespace App\Enums;

enum FinancialStatementItem: int
{
    case Unknown = 0;

    // Balance sheet
    case Cash = 1;
    case CashEquivalents = 2;
    case AccountsReceivable = 3;
    case Inventory = 4;
    case PrepaidExpenses = 5;
    case OtherCurrentAssets = 6;
    case TotalCurrentAssets = 7;
    case PropertyPlantEquipment = 8;
    case IntangibleAssets = 9;
    case Investments = 10;
    case OtherNonCurrentAssets = 11;
    case TotalAssets = 12;
    case AccountsPayable = 13;
    case ShortTermLoans = 14;
    case CurrentPortionLongTermDebt = 15;
    case OtherCurrentLiabilities = 16;
    case TotalCurrentLiabilities = 17;
    case LongTermLoans = 18;
    case OtherNonCurrentLiabilities = 19;
    case TotalLiabilities = 20;
    case ShareCapital = 21;
    case RetainedEarnings = 22;
    case Reserves = 23;
    case OtherEquity = 24;
    case TotalEquity = 25;

    // Income statement
    case Revenue = 26;
    case CostOfSales = 27;
    case GrossProfit = 28;
    case OperatingExpenses = 29;
    case OperatingIncome = 30;
    case FinanceCost = 31;
    case TaxExpense = 32;
    case NetProfit = 33;

    // Cash flow
    case OperatingCashFlow = 34;
    case InvestingCashFlow = 35;
    case FinancingCashFlow = 36;
    case OpeningCash = 37;
    case ClosingCash = 38;
    case NetCashChange = 39;

    // Equity
    case OpeningEquity = 40;
    case Capital = 41;
    case ClosingEquity = 42;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'unknown',

            self::Cash => 'cash',
            self::CashEquivalents => 'cash_equivalents',
            self::AccountsReceivable => 'accounts_receivable',
            self::Inventory => 'inventory',
            self::PrepaidExpenses => 'prepaid_expenses',
            self::OtherCurrentAssets => 'other_current_assets',
            self::TotalCurrentAssets => 'total_current_assets',
            self::PropertyPlantEquipment => 'property_plant_equipment',
            self::IntangibleAssets => 'intangible_assets',
            self::Investments => 'investments',
            self::OtherNonCurrentAssets => 'other_non_current_assets',
            self::TotalAssets => 'total_assets',
            self::AccountsPayable => 'accounts_payable',
            self::ShortTermLoans => 'short_term_loans',
            self::CurrentPortionLongTermDebt => 'current_portion_long_term_debt',
            self::OtherCurrentLiabilities => 'other_current_liabilities',
            self::TotalCurrentLiabilities => 'total_current_liabilities',
            self::LongTermLoans => 'long_term_loans',
            self::OtherNonCurrentLiabilities => 'other_non_current_liabilities',
            self::TotalLiabilities => 'total_liabilities',
            self::ShareCapital => 'share_capital',
            self::RetainedEarnings => 'retained_earnings',
            self::Reserves => 'reserves',
            self::OtherEquity => 'other_equity',
            self::TotalEquity => 'total_equity',

            self::Revenue => 'revenue',
            self::CostOfSales => 'cost_of_sales',
            self::GrossProfit => 'gross_profit',
            self::OperatingExpenses => 'operating_expenses',
            self::OperatingIncome => 'operating_income',
            self::FinanceCost => 'finance_cost',
            self::TaxExpense => 'tax_expense',
            self::NetProfit => 'net_profit',

            self::OperatingCashFlow => 'operating_cash_flow',
            self::InvestingCashFlow => 'investing_cash_flow',
            self::FinancingCashFlow => 'financing_cash_flow',
            self::OpeningCash => 'opening_cash',
            self::ClosingCash => 'closing_cash',
            self::NetCashChange => 'net_cash_change',

            self::OpeningEquity => 'opening_equity',
            self::Capital => 'capital',
            self::ClosingEquity => 'closing_equity',
        };
    }

    public static function fromLabel(?string $label): self
    {
        if (blank($label)) {
            return self::Unknown;
        }

        $normalized = strtolower(trim($label));

        foreach (self::cases() as $case) {
            if ($case->label() === $normalized) {
                return $case;
            }
        }

        return self::Unknown;
    }

    public static function labels(): array
    {
        return array_map(
            fn (self $case) => $case->label(),
            self::cases()
        );
    }

    public static function promptValues(): string
    {
        return implode("\n", array_map(
            fn (string $label) => "- {$label}",
            self::labels()
        ));
    }
}
