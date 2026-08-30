<?php

namespace App\AI\Services;

use App\Enums\TransactionPeriod;

class DocumentMergeService
{
    /**
     * @param array<int,array> $responses
     */
    public function merge(
        array $responses,
        string $documentType
    ): array {

        return match ($documentType) {

            'bank_statement'
            => $this->mergeBankStatement($responses),

            'financial_statement'
            => $this->mergeFinancialStatement($responses),

            default
            => throw new \RuntimeException(
                "Unsupported document type."
            ),
        };
    }


    private function mergeBankStatement(array $responses): array
    {
        $merged = [
            'document_type' => 'bank_statement',
            'overall_confidence' => 1,
            'warnings' => [],
            'bank_statement' => [
                'bank_name' => null,
                'branch' => null,
                'account_number' => null,
                'iban' => null,
                'currency' => null,
                'account_holder' => null,
                'statement_period' => [
                    'from' => null,
                    'to' => null,
                ],
                'opening_balance' => null,
                'closing_balance' => null,
                'transactions' => [],
            ],
        ];

        foreach ($responses as $response) {

            $current = $response['bank_statement'] ?? [];

            foreach (
                [
                    'bank_name',
                    'branch',
                    'account_number',
                    'iban',
                    'currency',
                    'account_holder',
                ] as $field
            ) {

                if (
                    empty($merged['bank_statement'][$field]) &&
                    !empty($current[$field])
                ) {

                    $merged['bank_statement'][$field] =
                        $current[$field];
                }
            }


            if (
                empty($merged['bank_statement']['statement_period']['from']) &&
                !empty($current['statement_period']['from'])
            ) {

                $merged['bank_statement']['statement_period']['from'] =
                    $current['statement_period']['from'];
            }


            if (
                !empty($current['statement_period']['to'])
            ) {

                $merged['bank_statement']['statement_period']['to'] =
                    $current['statement_period']['to'];
            }


            if (
                $merged['bank_statement']['opening_balance'] === null &&
                isset($current['opening_balance'])
            ) {

                $merged['bank_statement']['opening_balance'] =
                    $current['opening_balance'];
            }


            if (
                isset($current['closing_balance'])
            ) {

                $merged['bank_statement']['closing_balance'] =
                    $current['closing_balance'];
            }


            foreach ($current['transactions'] ?? [] as $transaction) {

                $merged['bank_statement']['transactions'][] =
                    $transaction;
            }


            $merged['warnings'] = array_merge(
                $merged['warnings'],
                $response['warnings'] ?? []
            );
        }


        $seen = [];

        $transactions = [];


        foreach ($merged['bank_statement']['transactions'] as $transaction) {


            $key = implode('|', [

                $transaction['posting_date'] ?? '',

                $transaction['value_date'] ?? '',

                $transaction['transaction_type'] ?? '',

                $transaction['description'] ?? '',

                $transaction['reference'] ?? '',

                number_format(
                    (float)(
                        ($transaction['debit'] ?? 0)
                        +
                        ($transaction['credit'] ?? 0)
                    ),
                    2
                )

            ]);


            if (!isset($seen[$key])) {

                $seen[$key] = true;

                $transactions[] = $transaction;
            }
        }


        usort(
            $transactions,
            fn($a, $b) => strcmp(
                $a['posting_date'] ?? '',
                $b['posting_date'] ?? ''
            )
        );


        $merged['bank_statement']['transactions'] =
            array_values($transactions);


        $merged['warnings'] =
            array_values(
                array_unique(
                    $merged['warnings']
                )
            );


        return $merged;
    }



    private function mergeFinancialStatement(
        array $responses
    ): array {

        $merged = [

            'document_type' => 'financial_statement',

            'overall_confidence' => 0,

            'warnings' => [],

            'reporting_periods' => [],

            'balance_sheet' => [],

            'income_statement' => [],

            'cash_flow_statement' => [],

            'equity_statement' => [],
        ];

        $confidence = [];

        foreach ($responses as $response) {

            /*
        |--------------------------------------------------------------------------
        | Overall confidence
        |--------------------------------------------------------------------------
        */

            if (
                isset($response['overall_confidence'])
                &&
                is_numeric($response['overall_confidence'])
            ) {
                $confidence[] =
                    (float) $response['overall_confidence'];
            }

            /*
        |--------------------------------------------------------------------------
        | Reporting periods
        |--------------------------------------------------------------------------
        */

            foreach (
                $this->extractReportingPeriods($response)
                as $period
            ) {

                if (
                    !is_array($period)
                    ||
                    empty($period['period_id'])
                ) {
                    continue;
                }

                $periodId = $this->canonicalPeriodId($period);

                /*
             * Do not overwrite an existing period with
             * another chunk's copy of the same period.
             */
                if (
                    !isset(
                        $merged['reporting_periods'][$periodId]
                    )
                ) {

                    $merged['reporting_periods'][$periodId] = [

                        'period_id' =>
                        $periodId,

                        'date' =>
                        $period['date'] ?? null,

                        'period_type' =>
                        $this->normalizePeriodType(
                            $period['period_type'] ?? null
                        ),

                        'label' =>
                        $period['label'] ?? $periodId,
                    ];
                } else {

                    /*
                 * Fill missing information from later chunks.
                 */
                    $existing =
                        &$merged['reporting_periods'][$periodId];

                    if (
                        empty($existing['date'])
                        &&
                        !empty($period['date'])
                    ) {
                        $existing['date'] =
                            $period['date'];

                        if ($this->looksLikeMonthEndDate($period['date'])) {
                            $existing['period_type'] = 'monthly';
                        }

                        if (!empty($period['label'])) {
                            $existing['label'] = $period['label'];
                        }
                    }

                    if (
                        (
                            empty($existing['period_type'])
                            ||
                            $existing['period_type'] === 'unknown'
                        )
                        &&
                        !empty($period['period_type'])
                    ) {
                        $existing['period_type'] =
                            $this->normalizePeriodType(
                                $period['period_type']
                            );
                    }

                    if (
                        empty($existing['label'])
                        &&
                        !empty($period['label'])
                    ) {
                        $existing['label'] =
                            $period['label'];
                    }
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Warnings
        |--------------------------------------------------------------------------
        */

            $merged['warnings'] = array_merge(

                $merged['warnings'],

                $response['warnings'] ?? []
            );

            /*
        |--------------------------------------------------------------------------
        | Financial sections
        |--------------------------------------------------------------------------
        */

            foreach (
                [
                    'balance_sheet',
                    'income_statement',
                    'cash_flow_statement',
                    'equity_statement',
                ]
                as $section
            ) {

                if (
                    empty($response[$section])
                    ||
                    !is_array($response[$section])
                ) {
                    continue;
                }

                foreach (
                    $response[$section]
                    as $field => $values
                ) {

                    /*
                 * New format:
                 *
                 * field => [
                 *     [
                 *         period_id,
                 *         value,
                 *         confidence,
                 *         source
                 *     ],
                 *     ...
                 * ]
                 */

                    if (
                        !is_array($values)
                        ||
                        empty($values)
                    ) {
                        continue;
                    }

                    /*
                 * Make sure the merged field exists.
                 */
                    if (
                        !isset(
                            $merged[$section][$field]
                        )
                        ||
                        !is_array(
                            $merged[$section][$field]
                        )
                    ) {
                        $merged[$section][$field] = [];
                    }

                    /*
                 * Merge by period_id.
                 */
                    $values = $this->normalizeIncomingMetricValues(
                        $values,
                        $response
                    );

                    foreach ($values as $incoming) {

                        if (! is_array($incoming)) {
                            continue;
                        }

                        if (
                            empty($incoming['period_id'])
                        ) {
                            $merged['warnings'][] =
                                "Skipped {$section}.{$field}: missing period_id";

                            continue;
                        }

                        if (
                            $this->isDifferencePeriod($incoming)
                        ) {
                            continue;
                        }

                        $incoming['period_id'] =
                            $this->canonicalPeriodId($incoming);

                        if ($incoming['period_id'] === '') {
                            $merged['warnings'][] =
                                "Skipped {$section}.{$field}: missing period_id";

                            continue;
                        }

                        $periodId =
                            (string) $incoming['period_id'];

                        /*
                     * Find existing value for this period.
                     */
                        $existingIndex =
                            $this->findPeriodValueIndex(
                                $merged[$section][$field],
                                $periodId
                            );

                        if ($existingIndex === null) {

                            $merged[$section][$field][] =
                                $this->normalizePeriodValue(
                                    $incoming
                                );

                            continue;
                        }

                        $merged[$section][$field][$existingIndex] =
                            $this->mergeFinancialPeriodValue(
                                $merged[$section][$field][$existingIndex],
                                $incoming
                            );
                    }
                }
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Overall confidence
    |--------------------------------------------------------------------------
    */

        if (!empty($confidence)) {

            $merged['overall_confidence'] =
                array_sum($confidence)
                /
                count($confidence);
        }

        /*
    |--------------------------------------------------------------------------
    | Clean warnings
    |--------------------------------------------------------------------------
    */

        $merged['warnings'] =
            array_values(
                array_unique(
                    array_filter(
                        $merged['warnings']
                    )
                )
            );



        $merged['reporting_periods'] =
            array_values(
                $merged['reporting_periods']
            );

        /*
    |--------------------------------------------------------------------------
    | Sort reporting periods
    |--------------------------------------------------------------------------
    */

        usort(
            $merged['reporting_periods'],
            function ($a, $b) {

                return strcmp(
                    (string) ($a['date'] ?? ''),
                    (string) ($b['date'] ?? '')
                );
            }
        );

        /*
    |--------------------------------------------------------------------------
    | Sort every financial field by period_id
    |--------------------------------------------------------------------------
    */

        foreach (
            [
                'balance_sheet',
                'income_statement',
                'cash_flow_statement',
                'equity_statement',
            ]
            as $section
        ) {

            foreach (
                $merged[$section]
                as $field => &$values
            ) {

                if (!is_array($values)) {
                    continue;
                }

                usort(
                    $values,
                    fn($a, $b) =>
                    strcmp(
                        (string) (
                            $a['period_id'] ?? ''
                        ),
                        (string) (
                            $b['period_id'] ?? ''
                        )
                    )
                );
            }

            unset($values);
        }

        return $merged;
    }
    private function findPeriodValueIndex(
        array $values,
        string $periodId
    ): ?int {

        foreach ($values as $index => $value) {

            if (
                is_array($value)
                &&
                (string) ($value['period_id'] ?? '')
                === $periodId
            ) {
                return $index;
            }
        }

        return null;
    }
    private function mergeFinancialPeriodValue(
        array $existing,
        array $incoming
    ): array {

        $existingValue =
            $existing['value'] ?? null;

        $incomingValue =
            $incoming['value'] ?? null;

        /*
     * Prefer a real value over null.
     */
        if (
            $existingValue === null
            &&
            $incomingValue !== null
        ) {

            $value = $incomingValue;
        } elseif (
            $existingValue !== null
            &&
            $incomingValue === null
        ) {

            $value = $existingValue;
        } elseif (
            $incomingValue !== null
        ) {

            $existingConfidence =
                (float) (
                    $existing['confidence'] ?? 0
                );

            $incomingConfidence =
                (float) (
                    $incoming['confidence'] ?? 0
                );

            if (
                $incomingConfidence
                >
                $existingConfidence
            ) {
                $value = $incomingValue;
            } else {
                $value = $existingValue;
            }
        } else {

            $value = null;
        }


        $existingConfidence =
            (float) (
                $existing['confidence'] ?? 0
            );

        $incomingConfidence =
            (float) (
                $incoming['confidence'] ?? 0
            );

        $confidence =
            max(
                $existingConfidence,
                $incomingConfidence
            );

        /*
     * Source
     */
        $existingSource =
            $existing['source'] ?? null;

        $incomingSource =
            $incoming['source'] ?? null;

        $source =
            $this->preferSource(
                $existingSource,
                $incomingSource,
                $incomingConfidence,
                $existingConfidence
            );

        return [

            'period_id' =>
            (string) (
                $existing['period_id']
                ??
                $incoming['period_id']
            ),

            'value' =>
            $value,

            'confidence' =>
            $confidence,

            'source' =>
            $source,
        ];
    }

    private function preferSource(
        mixed $existingSource,
        mixed $incomingSource,
        float $incomingConfidence,
        float $existingConfidence
    ): ?array {

        if (
            !is_array($existingSource)
            &&
            is_array($incomingSource)
        ) {
            return $incomingSource;
        }

        if (
            is_array($existingSource)
            &&
            !is_array($incomingSource)
        ) {
            return $existingSource;
        }

        if (
            !is_array($existingSource)
            &&
            !is_array($incomingSource)
        ) {
            return null;
        }

        $existingScore =
            $this->sourceStrengthScore(
                $existingSource
            );

        $incomingScore =
            $this->sourceStrengthScore(
                $incomingSource
            );

        /*
     * Prefer the stronger provenance.
     */
        if (
            $incomingScore
            >
            $existingScore
        ) {
            return $incomingSource;
        }

        if (
            $existingScore
            >
            $incomingScore
        ) {
            return $existingSource;
        }

        /*
     * Same source strength:
     * prefer the value with higher confidence.
     */
        return $incomingConfidence > $existingConfidence
            ? $incomingSource
            : $existingSource;
    }
    private function normalizePeriodValue(
        array $value
    ): array {

        return [

            'period_id' =>
            (string) (
                $value['period_id']
                ?? ''
            ),

            'value' =>
            array_key_exists(
                'value',
                $value
            )
                ? $value['value']
                : null,

            'confidence' =>
            is_numeric(
                $value['confidence'] ?? null
            )
                ? (float) $value['confidence']
                : 0,

            'source' =>
            is_array(
                $value['source'] ?? null
            )
                ? $value['source']
                : null,
        ];
    }





    private function sourceStrengthScore(array $source): int
    {
        $score = 0;

        if (!empty($source['sheet'])) {
            $score += 4;
        }

        if (!empty($source['page'])) {
            $score += 3;
        }

        if (!empty($source['row'])) {
            $score += 2;
        }

        if (!empty($source['cell'])) {
            $score += 2;
        }

        if (!empty($source['section'])) {
            $score += 1;
        }

        if (!empty($source['table'])) {
            $score += 1;
        }

        if (!empty($source['block'])) {
            $score += 1;
        }

        if (!empty($source['label'])) {
            $score += 1;
        }

        return $score;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractReportingPeriods(array $response): array
    {
        $periods = $response['reporting_periods'] ?? null;

        if (! is_array($periods) || $periods === []) {
            $legacy = $response['reporting_period'] ?? null;

            if (is_array($legacy)) {
                $periods = array_is_list($legacy) ? $legacy : [$legacy];
            } else {
                $periods = [];
            }
        }

        $normalized = [];

        foreach ($periods as $period) {
            if (! is_array($period) || $this->isDifferencePeriod($period)) {
                continue;
            }

            $periodId = trim((string) ($period['period_id'] ?? ''));

            if ($periodId === '') {
                $periodId = $this->makePeriodId($period);
            }

            if ($periodId === '') {
                continue;
            }

            $period['period_id'] = $periodId;

            $periodId = $this->canonicalPeriodId($period);

            if ($periodId === '') {
                continue;
            }

            $date = $period['date'] ?? null;
            $periodType = $this->normalizePeriodType(
                $period['period_type'] ?? null
            );

            if ($this->looksLikeMonthEndDate($date) && $periodType === 'quarterly') {
                $periodType = 'monthly';
            }

            $normalized[] = [
                'period_id' => $periodId,
                'date' => $date,
                'period_type' => $periodType,
                'label' => $period['label'] ?? $periodId,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeIncomingMetricValues(mixed $values, array $response): array
    {
        if (! is_array($values) || $values === []) {
            return [];
        }

        if (array_is_list($values)) {
            return $values;
        }

        if (
            array_key_exists('value', $values)
            || array_key_exists('amount', $values)
            || array_key_exists('confidence', $values)
        ) {
            $periodId = trim((string) ($values['period_id'] ?? ''));

            if ($periodId === '') {
                $periods = $this->extractReportingPeriods($response);

                if (count($periods) === 1) {
                    $periodId = (string) $periods[0]['period_id'];
                }
            }

            if ($periodId === '') {
                return [];
            }

            $values['period_id'] = $periodId;

            return [$values];
        }

        return [];
    }

    private function canonicalPeriodId(array $item): string
    {
        $id = trim((string) ($item['period_id'] ?? ''));
        $source = is_array($item['source'] ?? null) ? $item['source'] : null;
        $column = $this->excelColumnFrom($item, $source);

        if ($column !== null && $this->periodIdEncodesExcelColumn($id, $item['label'] ?? null)) {
            return 'col_'.$column;
        }

        if ($id === '') {
            return '';
        }

        return strtolower($id);
    }

    private function excelColumnFrom(array $item, ?array $source): ?string
    {
        $cell = is_string($source['cell'] ?? null) ? trim((string) $source['cell']) : '';

        if ($cell !== '' && preg_match('/^([A-Za-z]{1,3})\d+$/', $cell, $match)) {
            return strtoupper($match[1]);
        }

        $id = (string) ($item['period_id'] ?? '');
        $label = (string) ($item['label'] ?? '');

        if (preg_match('/(?:^col_|_col)([A-Za-z]{1,3})$/i', $id, $match)) {
            return strtoupper($match[1]);
        }

        if (preg_match('/Column\s+([A-Za-z]{1,3})\b/i', $label, $match)) {
            return strtoupper($match[1]);
        }

        if (
            preg_match('/_([A-Za-z]{1,3})$/', $id, $match)
            && ! preg_match('/_q[1-4]$/i', $id)
        ) {
            return strtoupper($match[1]);
        }

        return null;
    }

    private function periodIdEncodesExcelColumn(string $id, mixed $label): bool
    {
        if (preg_match('/(?:^col_|_col)[A-Za-z]{1,3}$/i', $id)) {
            return true;
        }

        if (is_string($label) && preg_match('/Column\s+[A-Za-z]{1,3}/i', $label)) {
            return true;
        }

        return (bool) (
            preg_match('/_[A-Za-z]{1,3}$/', $id)
            && ! preg_match('/_q[1-4]$/i', $id)
        );
    }

    private function looksLikeMonthEndDate(mixed $date): bool
    {
        if (! is_string($date) || trim($date) === '') {
            return false;
        }

        return (bool) preg_match(
            '/\d{1,2}\s*[-.\/]\s*[A-Za-z]{3,9}\s*[-.\/]\s*\d{2,4}/',
            trim($date)
        );
    }

    private function isDifferencePeriod(array $period): bool
    {
        $text = trim((string) (
            $period['label']
            ?? $period['period_id']
            ?? ''
        ));

        return (bool) preg_match('/^difference(\b|$|\s|\()/i', $text);
    }

    private function makePeriodId(array $period): string
    {
        $source = trim((string) (
            $period['label']
            ?? $period['date']
            ?? ''
        ));

        if ($source === '') {
            return '';
        }

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $source));

        return trim($slug, '_');
    }

    private function normalizePeriodType(mixed $periodType): string
    {
        return TransactionPeriod::fromLabel(
            is_string($periodType) ? $periodType : null
        )->label();
    }
}
