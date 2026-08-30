<?php

namespace App\Services\FinancialAnalysis;

use Carbon\Carbon;

class PeriodSelector
{
    /**
     * Infer the inclusive start/end dates covered by a reporting period.
     *
     * `period_date` is treated as the period end date.
     *
     * @return array{0: string, 1: string}
     */
    public function coverage(string $periodDate, string $periodType): array
    {
        $end = Carbon::parse($periodDate)->startOfDay();

        $start = match ($periodType) {
            'annual' => $end->copy()->subMonthsNoOverflow(11)->startOfMonth(),
            'quarterly' => $end->copy()->subMonthsNoOverflow(2)->startOfMonth(),
            'half_year' => $end->copy()->subMonthsNoOverflow(5)->startOfMonth(),
            'monthly' => $end->copy()->startOfMonth(),
            'ytd' => $end->copy()->startOfYear(),
            default => $end->copy(),
        };

        return [$start->toDateString(), $end->toDateString()];
    }

    public function inferPeriodType(
        ?string $periodType,
        string $periodKey,
        ?string $label = null
    ): string {
        $normalized = strtolower(trim((string) $periodType));

        if (in_array($normalized, ['annual', 'quarterly', 'monthly', 'half_year', 'ytd'], true)) {
            return $normalized;
        }

        $text = strtolower(trim($periodKey.' '.$label));

        if (preg_match('/\bq\s*[1-4]\b/', $text) || str_contains($text, 'quarter')) {
            return 'quarterly';
        }

        if (preg_match('/\bh\s*[12]\b/', $text) || str_contains($text, 'half')) {
            return 'half_year';
        }

        if (str_contains($text, 'ytd') || str_contains($text, 'year to date')) {
            return 'ytd';
        }

        if (
            str_contains($text, 'annual')
            || preg_match('/\b(fy|year)\b/', $text)
        ) {
            return 'annual';
        }

        if (preg_match(
            '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/',
            $text
        )) {
            return 'monthly';
        }

        return 'unknown';
    }

    /**
     * Keep one period per coverage window.
     *
     * Prefer a clearly final/audited version. Otherwise prefer higher confidence.
     * If the winner is still ambiguous, drop the group.
     *
     * @param  list<ReportingPeriod>  $periods
     * @return array{0: list<ReportingPeriod>, 1: list<array<string,string>>}
     */
    public function resolveVersions(array $periods): array
    {
        $groups = [];

        foreach ($periods as $period) {
            $groups[$period->coverageKey()][] = $period;
        }

        $resolved = [];
        $warnings = [];

        foreach ($groups as $coverageKey => $candidates) {
            if (count($candidates) === 1) {
                $resolved[] = $candidates[0];

                continue;
            }

            usort($candidates, function (ReportingPeriod $a, ReportingPeriod $b) {
                $rank = $this->versionRank($b) <=> $this->versionRank($a);

                if ($rank !== 0) {
                    return $rank;
                }

                return ($b->confidence ?? -1) <=> ($a->confidence ?? -1);
            });

            $best = $candidates[0];
            $tied = array_filter(
                $candidates,
                function (ReportingPeriod $candidate) use ($best) {
                    return $this->versionRank($candidate) === $this->versionRank($best)
                        && $this->confidenceValue($candidate) === $this->confidenceValue($best)
                        && $candidate->periodKey !== $best->periodKey;
                }
            );

            if ($tied !== []) {
                $warnings[] = [
                    'code' => 'ambiguous_period_version',
                    'message' => sprintf(
                        'Multiple versions cover %s to %s (%s) with no clear audited/final or confidence winner.',
                        $best->startsOn,
                        $best->endsOn,
                        implode(', ', array_map(
                            fn (ReportingPeriod $period) => $period->periodKey,
                            $candidates
                        ))
                    ),
                ];

                continue;
            }

            $resolved[] = $best;
        }

        return [$resolved, $warnings];
    }

    /**
     * Latest reporting period whose end date falls inside the requested range.
     *
     * @param  list<ReportingPeriod>  $periods
     */
    public function selectBalanceSheetPeriod(
        array $periods,
        string $from,
        string $to
    ): ?ReportingPeriod {
        $inside = array_values(array_filter(
            $periods,
            fn (ReportingPeriod $period) => $period->periodDate >= $from
                && $period->periodDate <= $to
        ));

        if ($inside === []) {
            return null;
        }

        usort($inside, function (ReportingPeriod $a, ReportingPeriod $b) {
            $date = $b->periodDate <=> $a->periodDate;

            if ($date !== 0) {
                return $date;
            }

            $rank = $this->versionRank($b) <=> $this->versionRank($a);

            if ($rank !== 0) {
                return $rank;
            }

            return ($b->confidence ?? -1) <=> ($a->confidence ?? -1);
        });

        return $inside[0];
    }

    /**
     * Non-overlapping periods fully covering [from, to], without double counting.
     *
     * Preference:
     * 1. exact reporting period matching the requested range
     * 2. exact annual periods that tile the range
     * 3. compatible non-overlapping periods (coarsest first)
     *
     * @param  list<ReportingPeriod>  $periods
     * @return array{0: list<ReportingPeriod>|null, 1: list<array<string,string>>}
     */
    public function selectFlowPeriods(
        array $periods,
        string $from,
        string $to
    ): array {
        $contained = array_values(array_filter(
            $periods,
            fn (ReportingPeriod $period) => $period->containedIn($from, $to)
                && $period->periodType !== 'unknown'
        ));

        if ($contained === []) {
            return [null, [[
                'code' => 'no_flow_periods',
                'section' => 'flow',
                'message' => sprintf(
                    'No reporting periods with a known type fall fully inside %s to %s.',
                    $from,
                    $to
                ),
            ]]];
        }

        $exact = array_values(array_filter(
            $contained,
            fn (ReportingPeriod $period) => $period->matchesRange($from, $to)
        ));

        if (count($exact) === 1) {
            return [$exact, []];
        }

        $annuals = array_values(array_filter(
            $contained,
            fn (ReportingPeriod $period) => $period->periodType === 'annual'
        ));

        usort($annuals, fn (ReportingPeriod $a, ReportingPeriod $b) => $a->startsOn <=> $b->startsOn);

        if ($this->coversRange($annuals, $from, $to)) {
            return [$annuals, []];
        }

        $selected = $this->selectCoarsestNonOverlapping($contained);

        if ($this->coversRange($selected, $from, $to)) {
            return [$selected, []];
        }

        return [null, [[
            'code' => 'incomplete_period_coverage',
            'section' => 'flow',
            'message' => sprintf(
                'No non-overlapping set of reporting periods fully covers %s to %s without gaps or double counting.',
                $from,
                $to
            ),
        ]]];
    }

    /**
     * Latest period ending strictly before the requested start date.
     *
     * @param  list<ReportingPeriod>  $periods
     */
    public function selectOpeningPeriod(
        array $periods,
        string $from
    ): ?ReportingPeriod {
        $before = array_values(array_filter(
            $periods,
            fn (ReportingPeriod $period) => $period->periodDate < $from
        ));

        if ($before === []) {
            return null;
        }

        usort($before, function (ReportingPeriod $a, ReportingPeriod $b) {
            $date = $b->periodDate <=> $a->periodDate;

            if ($date !== 0) {
                return $date;
            }

            return $this->versionRank($b) <=> $this->versionRank($a);
        });

        return $before[0];
    }

    public function versionRank(ReportingPeriod $period): int
    {
        $text = strtolower(trim($period->periodKey.' '.$period->label));

        $isDraft = str_contains($text, 'draft');
        $isAudit = str_contains($text, 'audit');
        $isFinal = str_contains($text, 'final');

        if ($isAudit && $isFinal) {
            return 100;
        }

        if ($isFinal && ! $isDraft) {
            return 80;
        }

        if ($isAudit && ! $isDraft) {
            return 60;
        }

        if ($isDraft) {
            return 10;
        }

        return 40;
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     * @return list<ReportingPeriod>
     */
    public function selectCoarsestNonOverlapping(array $periods): array
    {
        usort($periods, function (ReportingPeriod $a, ReportingPeriod $b) {
            $duration = $b->durationDays() <=> $a->durationDays();

            if ($duration !== 0) {
                return $duration;
            }

            return $a->startsOn <=> $b->startsOn;
        });

        $selected = [];

        foreach ($periods as $period) {
            foreach ($selected as $existing) {
                if ($period->overlaps($existing)) {
                    continue 2;
                }
            }

            $selected[] = $period;
        }

        usort($selected, fn (ReportingPeriod $a, ReportingPeriod $b) => $a->startsOn <=> $b->startsOn);

        return $selected;
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     */
    public function coversRange(array $periods, string $from, string $to): bool // if q of the q's is missing and requst ask for complet year 
    {
        if ($periods === []) {
            return false;
        }

        usort($periods, fn (ReportingPeriod $a, ReportingPeriod $b) => $a->startsOn <=> $b->startsOn);

        if ($periods[0]->startsOn !== $from) {
            return false;
        }

        $last = $periods[array_key_last($periods)];

        if ($last->endsOn !== $to) {
            return false;
        }

        for ($i = 1, $count = count($periods); $i < $count; $i++) {
            $expectedStart = Carbon::parse($periods[$i - 1]->endsOn)
                ->addDay()
                ->toDateString();

            if ($periods[$i]->startsOn !== $expectedStart) {
                return false;
            }
        }

        return true;
    }

    private function confidenceValue(ReportingPeriod $period): float
    {
        return $period->confidence ?? -1;
    }
}
