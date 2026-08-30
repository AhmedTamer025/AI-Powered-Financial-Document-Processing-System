<?php

namespace App\Enums;

enum FinancialStatementCategory: int
{
    case Unknown = 0;
    case BalanceSheet = 1;
    case IncomeStatement = 2;
    case CashFlowStatement = 3;
    case EquityStatement = 4;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'unknown',
            self::BalanceSheet => 'balance_sheet',
            self::IncomeStatement => 'income_statement',
            self::CashFlowStatement => 'cash_flow_statement',
            self::EquityStatement => 'equity_statement',
        };
    }

    public static function fromLabel(?string $label): self
    {
        if (blank($label)) {
            return self::Unknown;
        }

        return match (strtolower(trim($label))) {
            'balance_sheet',
            'balance sheet' => self::BalanceSheet,

            'income_statement',
            'income statement' => self::IncomeStatement,

            'cash_flow_statement',
            'cash flow statement',
            'cash_flow' => self::CashFlowStatement,

            'equity_statement',
            'equity statement' => self::EquityStatement,

            default => self::Unknown,
        };
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
