<?php

namespace App\Services\FinancialAnalysis;

use Carbon\Carbon;

final class ReportingPeriod
{
    public function __construct(
        public readonly string $periodKey,
        public readonly string $periodDate,
        public readonly string $periodType,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly ?string $label = null,
        public readonly ?float $confidence = null,
    ) {}

    public function coverageKey(): string
    {
        return $this->startsOn.'|'.$this->endsOn;
    }

    public function durationDays(): int
    {
        return Carbon::parse($this->startsOn)
            ->diffInDays(Carbon::parse($this->endsOn)) + 1;
    }

    public function overlaps(self $other): bool //Do these two periods share any dates Q1:Jan 1 → Mar 31 H1:Jan 1 → Jun 30
    {
        return $this->startsOn <= $other->endsOn
            && $other->startsOn <= $this->endsOn;
    }

    public function containedIn(string $from, string $to): bool //Is this entire reporting period inside the requested date range?
    {
        return $this->startsOn >= $from && $this->endsOn <= $to;
    }

    public function matchesRange(string $from, string $to): bool//Does this financial period exactly equal the requested date range?
    {
        return $this->startsOn === $from && $this->endsOn === $to;
    }
}
