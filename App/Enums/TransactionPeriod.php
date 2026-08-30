<?php

namespace App\Enums;

enum TransactionPeriod: int
{
    case Unknown = 0;
    case Annual = 1;
    case Quarterly = 2;
    case Monthly = 3;
    case HalfYear = 4;
    case Ytd = 5;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'unknown',
            self::Annual => 'annual',
            self::Quarterly => 'quarterly',
            self::Monthly => 'monthly',
            self::HalfYear => 'half_year',
            self::Ytd => 'ytd',
        };
    }

    public static function fromLabel(?string $label): self
    {
        if (blank($label)) {
            return self::Unknown;
        }

        return match (strtolower(trim($label))) {
            'annual',
            'annually',
            'year',
            'yearly' => self::Annual,

            'quarter',
            'quarterly' => self::Quarterly,

            'month',
            'monthly' => self::Monthly,

            'half year',
            'half_year',
            'semi annual',
            'semiannual' => self::HalfYear,

            'ytd',
            'year to date' => self::Ytd,

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
