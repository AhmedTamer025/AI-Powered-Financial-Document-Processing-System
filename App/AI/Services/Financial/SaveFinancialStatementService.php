<?php

namespace App\AI\Services\Financial;

use App\Enums\FinancialStatementCategory;
use App\Enums\FinancialStatementItem as FinancialStatementItemEnum;
use App\Enums\TransactionPeriod;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaveFinancialStatementService
{
    public function execute(
        array $data,
        string $businessId,
        string $aiResultId,
        array $fileInfo
    ): FinancialStatement {

        Log::info('SaveFinancialStatementService started', [
            'business_id' => $businessId,
            'ai_result_id' => $aiResultId,
        ]);

        return DB::transaction(function () use (
            $data,
            $businessId,
            $aiResultId,
            $fileInfo
        ) {

            $statement = FinancialStatement::query()
                ->where('business_id', $businessId)
                ->where('ai_result_id', $aiResultId)
                ->firstOrFail();

            $reportingPeriods = $this->extractReportingPeriods($data);

            $periodLookup = [];

            foreach ($reportingPeriods as $period) {
                $periodId = (string) $period['period_id'];
                $periodLookup[$periodId] = $period;
            }

            Log::info('Financial statement reporting periods detected', [
                'ai_result_id' => $aiResultId,
                'count' => count($reportingPeriods),
                'period_ids' => array_keys($periodLookup),
            ]);

            $primaryPeriod = $this->choosePrimaryPeriod($reportingPeriods);

            $periodType = TransactionPeriod::fromLabel(
                $primaryPeriod['period_type'] ?? null
            );

            $statementType = strtolower(
                trim(
                    $data['statement_type']
                    ?? $data['document_type']
                    ?? 'balance_sheet'
                )
            );

            $statement->update([
                'original_file_name' =>
                    $fileInfo['original_file_name'] ?? null,
                'stored_file_name' =>
                    $fileInfo['stored_file_name'] ?? null,
                'stored_path' =>
                    $fileInfo['stored_path'] ?? null,
                'extension' =>
                    $fileInfo['extension'] ?? null,
                'mime_type' =>
                    $fileInfo['mime_type'] ?? null,
                'size' =>
                    $fileInfo['size'] ?? null,
                'statement_type' => $statementType,
                'period_type' => $periodType,
                'date' => $this->normalizeDate(
                    $primaryPeriod['date']
                    ?? $primaryPeriod['to']
                    ?? $primaryPeriod['from']
                    ?? null,
                    $fileInfo['original_file_name'] ?? null
                ),
            ]);

            $sections = [
                'balance_sheet',
                'income_statement',
                'cash_flow_statement',
                'equity_statement',
            ];

            $savedPerPeriod = [];

            $skippedPerPeriod = [];

            $statement->items()->delete();

            foreach ($sections as $section) {

                if (
                    empty($data[$section])
                    || ! is_array($data[$section])
                ) {
                    continue;
                }

                $category = FinancialStatementCategory::fromLabel(
                    $section
                );

                foreach ($data[$section] as $metric => $value) {

                    $item = FinancialStatementItemEnum::fromLabel(
                        $metric
                    );

                    $label = ucfirst(
                        str_replace('_', ' ', $metric)
                    );

                    foreach (
                        $this->periodValues($value, $periodLookup)
                        as $periodValue
                    ) {

                        $periodKey = trim((string) (
                            $periodValue['period_id'] ?? ''
                        ));

                        if ($periodKey === '') {
                            Log::warning(
                                'Skipped financial value without period_id',
                                [
                                    'ai_result_id' => $aiResultId,
                                    'section' => $section,
                                    'metric' => $metric,
                                ]
                            );

                            continue;
                        }

                        $amount =
                            $periodValue['value']
                            ?? $periodValue['amount']
                            ?? null;

                        if (! is_numeric($amount)) {
                            continue;
                        }

                        $confidence =
                            is_numeric($periodValue['confidence'] ?? null)
                                ? $periodValue['confidence']
                                : null;

                        $source = $periodValue['source'] ?? null;

                        $periodMeta = $periodLookup[$periodKey] ?? [];

                        $itemPeriodType = TransactionPeriod::fromLabel(
                            $periodMeta['period_type']
                            ?? $periodValue['period_type']
                            ?? null
                        );

                        $periodDate = $this->normalizeDate(
                            $periodMeta['date'] ?? null
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Cross-Statement Duplicate Check
                        |--------------------------------------------------------------------------
                        |
                        | Different uploaded files always create separate FinancialStatement
                        | rows (keyed by ai_result_id), so the per-statement items()->delete()
                        | above only protects against reprocessing the SAME file. This is the
                        | only place that catches the same period being uploaded again in a
                        | different document (e.g. Dec-24 appearing in both a Q4 file and a
                        | later multi-period file).
                        |
                        | Matching is done on the normalized `period_date`, not the raw
                        | AI-provided period_id/label, since two files can describe the same
                        | date with different labels ("Dec-24" vs "31-Dec-2024").
                        |
                        | Match key is period_date + category + name only (amount is NOT
                        | compared). If this period/item combination already exists under
                        | another statement for this business, it is skipped entirely,
                        | regardless of whether the amount matches.
                        |
                        */

                        if ($periodDate) {

                            $isDuplicate = FinancialStatementItem::query()
                                ->where('period_date', $periodDate)
                                ->where('category', $category)
                                ->where('name', $item)
                                ->whereHas('financialStatement', function ($q) use (
                                    $businessId,
                                    $statementType,
                                    $statement
                                ) {
                                    $q->where('business_id', $businessId)
                                        ->where('statement_type', $statementType)
                                        ->where('id', '!=', $statement->id);
                                })
                                ->exists();

                            if ($isDuplicate) {

                                Log::info('Skipped duplicate period value (period/item already exists under another statement)', [
                                    'ai_result_id' => $aiResultId,
                                    'business_id' => $businessId,
                                    'category' => $category->value,
                                    'name' => $item->value,
                                    'period_date' => $periodDate,
                                    'amount' => $amount,
                                ]);

                                $skippedPerPeriod[$periodKey] =
                                    ($skippedPerPeriod[$periodKey] ?? 0) + 1;

                                continue;
                            }
                        }

                        $statement->items()->updateOrCreate(
                            [
                                'period_key' => $periodKey,
                                'category' => $category,
                                'name' => $item,
                            ],
                            [
                                'label' => $label,
                                'period_date' => $periodDate,
                                'period_type' => $itemPeriodType->label(),
                                'amount' => $amount,
                                'confidence' => $confidence,
                                'metadata' => [
                                    'source' => 'ai',
                                    'ai_result_id' => $aiResultId,
                                    'confidence' => $confidence,
                                    'provenance' => $source,
                                    'original_name' => $metric,
                                    'period_id' => $periodKey,
                                    'period_label' =>
                                        $periodMeta['label'] ?? $periodKey,
                                ],
                            ]
                        );

                        $savedPerPeriod[$periodKey] =
                            ($savedPerPeriod[$periodKey] ?? 0) + 1;
                    }
                }
            }

            Log::info('Financial statement values saved per period', [
                'ai_result_id' => $aiResultId,
                'financial_statement_id' => $statement->id,
                'saved_per_period' => $savedPerPeriod,
                'skipped_duplicates_per_period' => $skippedPerPeriod,
            ]);

            return $statement;
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractReportingPeriods(array $data): array
    {
        $periods = $data['reporting_periods'] ?? null;

        if (! is_array($periods) || $periods === []) {
            $legacy = $data['reporting_period'] ?? null;

            if (is_array($legacy)) {
                $periods = array_is_list($legacy) ? $legacy : [$legacy];
            } else {
                $periods = [];
            }
        }

        $normalized = [];

        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $periodId = trim((string) ($period['period_id'] ?? ''));

            if ($periodId === '') {
                $label = trim((string) (
                    $period['label'] ?? $period['date'] ?? ''
                ));

                if ($label === '') {
                    continue;
                }

                $periodId = trim(
                    strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $label)),
                    '_'
                );
            }

            if ($periodId === '') {
                continue;
            }

            $normalized[$periodId] = [
                'period_id' => $periodId,
                'date' => $period['date'] ?? $period['to'] ?? $period['from'] ?? null,
                'period_type' => TransactionPeriod::fromLabel(
                    $period['period_type'] ?? null
                )->label(),
                'label' => $period['label'] ?? $periodId,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param  array<int,array<string,mixed>>  $periods
     * @return array<string,mixed>
     */
    private function choosePrimaryPeriod(array $periods): array
    {
        $best = $periods[0] ?? [];
        $bestTimestamp = null;

        foreach ($periods as $period) {
            $parsed = $this->normalizeDate($period['date'] ?? null);

            if ($parsed === null) {
                continue;
            }

            $timestamp = strtotime($parsed);

            if ($bestTimestamp === null || $timestamp >= $bestTimestamp) {
                $best = $period;
                $bestTimestamp = $timestamp;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,array<string,mixed>>  $periodLookup
     * @return array<int,array<string,mixed>>
     */
    private function periodValues(mixed $value, array $periodLookup): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        if (
            is_array($value)
            && (
                array_key_exists('value', $value)
                || array_key_exists('amount', $value)
                || array_key_exists('confidence', $value)
            )
        ) {
            $periodId = trim((string) ($value['period_id'] ?? ''));

            if ($periodId === '' && count($periodLookup) === 1) {
                $periodId = (string) array_key_first($periodLookup);
                $value['period_id'] = $periodId;
            }

            return $periodId === '' ? [] : [$value];
        }

        return [];
    }

    private function normalizeDate(
        mixed $date,
        ?string $fileName = null
    ): ?string {

        $candidates = [];

        if (! blank($date)) {
            $candidates[] = trim((string) $date);
        }

        if (! blank($fileName)) {
            $candidates[] = pathinfo(
                (string) $fileName,
                PATHINFO_FILENAME
            );
        }

        foreach ($candidates as $candidate) {
            $parsed = $this->parseReportingDate($candidate);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        if (! blank($date)) {
            Log::warning(
                'Unable to normalize financial statement date',
                [
                    'date' => $date,
                    'file_name' => $fileName,
                ]
            );
        }

        return null;
    }

    private function parseReportingDate(string $date): ?string
    {
        $date = trim($date);

        if ($date === '') {
            return null;
        }

        if (
            preg_match(
                '/(?:^|[^A-Z])Q\s*([1-4])\s*[-\/]?\s*(\d{2,4})(?:\b|$)/i',
                $date,
                $match
            )
        ) {
            return $this->quarterEndDate((int) $match[1], $match[2]);
        }

        if (
            preg_match(
                '/(\d{2,4})\s*Q\s*([1-4])(?:\b|$)/i',
                $date,
                $match
            )
        ) {
            return $this->quarterEndDate((int) $match[2], $match[1]);
        }

        if (
            preg_match(
                '/(?:^|[^A-Z])H\s*([12])\s*[-\/]?\s*(\d{2,4})(?:\b|$)/i',
                $date,
                $match
            )
        ) {
            $year = $this->normalizeYear($match[2]);
            $month = ((int) $match[1] === 1) ? 6 : 12;

            return Carbon::createFromDate($year, $month, 1)
                ->endOfMonth()
                ->format('Y-m-d');
        }

        if (
            preg_match(
                '/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\s*[.\-\/]?\s*(\d{2,4})\b/i',
                $date,
                $match
            )
        ) {
            $year = $this->normalizeYear($match[2]);

            try {
                return Carbon::parse($match[1].' '.$year)
                    ->endOfMonth()
                    ->format('Y-m-d');
            } catch (\Throwable $e) {
                // Continue.
            }
        }

        $formats = [
            'dMY',
            'd M Y',
            'd M y',
            'd-M-Y',
            'd-M-y',
            'd/m/Y',
            'd/m/y',
            'j/n/Y',
            'j/n/y',
            'j/m/Y',
            'j/m/y',
            'd/n/Y',
            'd/n/y',
            'd-m-Y',
            'd-m-y',
            'j-n-Y',
            'j-n-y',
            'Y-m-d',
            'Y/m/d',
            'd.m.Y',
            'd.m.y',
            'j.n.Y',
            'j.n.y',
            'M Y',
            'F Y',
            'M y',
            'F y',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $date);

                if ($parsed !== false) {
                    return $parsed->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                // Try next format.
            }
        }

        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function quarterEndDate(int $quarter, string $year): string
    {
        $year = $this->normalizeYear($year);
        $month = $quarter * 3;

        return Carbon::createFromDate($year, $month, 1)
            ->endOfMonth()
            ->format('Y-m-d');
    }

    private function normalizeYear(string $year): int
    {
        $year = (int) $year;

        if ($year < 100) {
            return $year >= 70
                ? 1900 + $year
                : 2000 + $year;
        }

        return $year;
    }
}