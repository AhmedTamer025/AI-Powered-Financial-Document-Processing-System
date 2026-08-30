<?php

namespace App\Enums;

enum DocumentType: int
{
    case BankStatement = 1;
    case FinancialStatement = 2;
    case Unsupported = 3;

    public function label(): string
    {
        return match ($this) {
            self::BankStatement => 'bank_statement',
            self::FinancialStatement => 'financial_statement',
            self::Unsupported => 'unsupported',
        };
    }

    public static function fromLabel(string $label): self
    {
        return match (strtolower(trim($label))) {

            'bank_statement',
            'bank statement' =>
                self::BankStatement,

            'financial_statement',
            'financial statement' =>
                self::FinancialStatement,

            'unsupported',
            'unknown' =>
                self::Unsupported,

            default =>
                self::Unsupported,
        };
    }
}
