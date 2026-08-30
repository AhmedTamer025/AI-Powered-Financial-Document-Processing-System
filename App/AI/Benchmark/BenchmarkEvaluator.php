<?php

namespace App\AI\Benchmark;

class BenchmarkEvaluator
{
    public function evaluate(
        array $groundTruth,
        array $prediction
    ): array {
        $correct = 0;
        $incorrect = 0;
        $missing = 0;
        $extra = 0;

        $fieldResults = [];

        /*
        |--------------------------------------------------------------------------
        | Flatten normal fields
        |--------------------------------------------------------------------------
        */

        $expectedFields = $this->flatten(
            $groundTruth
        );

        $actualFields = $this->flatten(
            $prediction
        );

        /*
        |--------------------------------------------------------------------------
        | Evaluate normal fields
        |--------------------------------------------------------------------------
        */

        foreach ($expectedFields as $field => $expectedValue) {

            /*
             * Transactions are evaluated separately.
             */
            if (
                str_starts_with(
                    $field,
                    'transactions'
                )
            ) {
                continue;
            }

            $actualValue = data_get(
                $prediction,
                $field
            );

            /*
             * Missing
             */
            if ($this->isMissing($actualValue)) {

                $missing++;

                $fieldResults[] = [
                    'field' => $field,
                    'status' => 'missing',
                    'expected_value' => $expectedValue,
                    'actual_value' => null,
                ];

                continue;
            }

            /*
             * Correct
             */
            if (
                $this->valuesEqual(
                    $expectedValue,
                    $actualValue,
                    $field
                )
            ) {

                $correct++;

                $fieldResults[] = [
                    'field' => $field,
                    'status' => 'correct',
                    'expected_value' => $expectedValue,
                    'actual_value' => $actualValue,
                ];

                continue;
            }

            /*
             * Incorrect
             */
            $incorrect++;

            $fieldResults[] = [
                'field' => $field,
                'status' => 'incorrect',
                'expected_value' => $expectedValue,
                'actual_value' => $actualValue,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Evaluate transactions
        |--------------------------------------------------------------------------
        */

        $expectedTransactions =
            $groundTruth['transactions'] ?? [];

        $actualTransactions =
            $prediction['transactions'] ?? [];

        if (!is_array($expectedTransactions)) {
            $expectedTransactions = [];
        }

        if (!is_array($actualTransactions)) {
            $actualTransactions = [];
        }

        $expectedCount = count(
            $expectedTransactions
        );

        $actualCount = count(
            $actualTransactions
        );

        $maxTransactions = max(
            $expectedCount,
            $actualCount
        );

        for (
            $index = 0;
            $index < $maxTransactions;
            $index++
        ) {

            /*
             * --------------------------------------------------------------
             * Missing transaction
             * --------------------------------------------------------------
             */

            if (
                !array_key_exists(
                    $index,
                    $actualTransactions
                )
            ) {

                $expectedTransaction =
                    $expectedTransactions[$index];

                foreach (
                    $expectedTransaction
                    as $field => $expectedValue
                ) {

                    $missing++;

                    $fieldResults[] = [
                        'field' =>
                            "transactions.{$index}.{$field}",

                        'status' =>
                            'missing',

                        'expected_value' =>
                            $expectedValue,

                        'actual_value' =>
                            null,
                    ];
                }

                continue;
            }

            /*
             * --------------------------------------------------------------
             * Extra transaction
             * --------------------------------------------------------------
             */

            if (
                !array_key_exists(
                    $index,
                    $expectedTransactions
                )
            ) {

                $actualTransaction =
                    $actualTransactions[$index];

                foreach (
                    $actualTransaction
                    as $field => $actualValue
                ) {

                    $extra++;

                    $fieldResults[] = [
                        'field' =>
                            "transactions.{$index}.{$field}",

                        'status' =>
                            'extra',

                        'expected_value' =>
                            null,

                        'actual_value' =>
                            $actualValue,
                    ];
                }

                continue;
            }

            /*
             * --------------------------------------------------------------
             * Compare transaction fields
             * --------------------------------------------------------------
             */

            $expectedTransaction =
                $expectedTransactions[$index];

            $actualTransaction =
                $actualTransactions[$index];

            /*
             * Fields expected by ground truth.
             */
            foreach (
                $expectedTransaction
                as $field => $expectedValue
            ) {

                $actualValue =
                    $actualTransaction[$field]
                    ?? null;

                $fieldPath =
                    "transactions.{$index}.{$field}";

                /*
                 * Missing transaction field
                 */
                if (
                    $this->isMissing(
                        $actualValue
                    )
                ) {

                    $missing++;

                    $fieldResults[] = [
                        'field' =>
                            $fieldPath,

                        'status' =>
                            'missing',

                        'expected_value' =>
                            $expectedValue,

                        'actual_value' =>
                            null,
                    ];

                    continue;
                }

                /*
                 * Correct transaction field
                 */
                if (
                    $this->valuesEqual(
                        $expectedValue,
                        $actualValue,
                        $field
                    )
                ) {

                    $correct++;

                    $fieldResults[] = [
                        'field' =>
                            $fieldPath,

                        'status' =>
                            'correct',

                        'expected_value' =>
                            $expectedValue,

                        'actual_value' =>
                            $actualValue,
                    ];

                    continue;
                }

                /*
                 * Incorrect transaction field
                 */
                $incorrect++;

                $fieldResults[] = [
                    'field' =>
                        $fieldPath,

                    'status' =>
                        'incorrect',

                    'expected_value' =>
                        $expectedValue,

                    'actual_value' =>
                        $actualValue,
                ];
            }

            /*
             * Extra transaction fields
             */
            foreach (
                $actualTransaction
                as $field => $actualValue
            ) {

                if (
                    !array_key_exists(
                        $field,
                        $expectedTransaction
                    )
                ) {

                    $extra++;

                    $fieldResults[] = [
                        'field' =>
                            "transactions.{$index}.{$field}",

                        'status' =>
                            'extra',

                        'expected_value' =>
                            null,

                        'actual_value' =>
                            $actualValue,
                    ];
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Extra normal fields
        |--------------------------------------------------------------------------
        */

        foreach (
            $actualFields as $field => $actualValue
        ) {

            if (
                str_starts_with(
                    $field,
                    'transactions'
                )
            ) {
                continue;
            }

            if (
                !array_key_exists(
                    $field,
                    $expectedFields
                )
            ) {

                $extra++;

                $fieldResults[] = [
                    'field' =>
                        $field,

                    'status' =>
                        'extra',

                    'expected_value' =>
                        null,

                    'actual_value' =>
                        $actualValue,
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Accuracy
        |--------------------------------------------------------------------------
        */

        $totalExpected =
            $correct +
            $incorrect +
            $missing;

        $accuracy =
            $totalExpected === 0
                ? 0
                : $correct / $totalExpected;

        return [
            'accuracy' =>
                round(
                    $accuracy,
                    4
                ),

            'correct_fields' =>
                $correct,

            'incorrect_fields' =>
                $incorrect,

            'missing_fields' =>
                $missing,

            'extra_fields' =>
                $extra,

            'total_expected_fields' =>
                $totalExpected,

            'field_results' =>
                $fieldResults,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Flatten associative arrays
    |--------------------------------------------------------------------------
    */

    protected function flatten(
        array $data,
        string $prefix = ''
    ): array {
        $result = [];

        foreach (
            $data as $key => $value
        ) {

            $path =
                $prefix === ''
                    ? $key
                    : "{$prefix}.{$key}";

            /*
             * Transactions are evaluated separately.
             */
            if ($key === 'transactions') {
                continue;
            }

            if (
                is_array($value) &&
                $this->isAssociative($value)
            ) {

                $result +=
                    $this->flatten(
                        $value,
                        $path
                    );

                continue;
            }

            $result[$path] =
                $value;
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Associative array detection
    |--------------------------------------------------------------------------
    */

    protected function isAssociative(
        array $array
    ): bool {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !==
            range(
                0,
                count($array) - 1
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Missing value
    |--------------------------------------------------------------------------
    */

    protected function isMissing(
        mixed $value
    ): bool {

        return
            $value === null ||
            (
                is_string($value) &&
                trim($value) === ''
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Compare values
    |--------------------------------------------------------------------------
    */

    protected function valuesEqual(
        mixed $expected,
        mixed $actual,
        ?string $field = null
    ): bool {

        /*
         * Both null.
         */
        if (
            $expected === null &&
            $actual === null
        ) {
            return true;
        }

        /*
         * One null.
         */
        if (
            $expected === null ||
            $actual === null
        ) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Dates
        |--------------------------------------------------------------------------
        */

        if (
            $this->isDateField($field)
        ) {

            $expectedDate =
                $this->normalizeDate(
                    $expected
                );

            $actualDate =
                $this->normalizeDate(
                    $actual
                );

            if (
                $expectedDate !== null &&
                $actualDate !== null
            ) {

                return
                    $expectedDate ===
                    $actualDate;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Debit / credit amounts
        |--------------------------------------------------------------------------
        |
        | Some models return debit as:
        |
        |     48
        |
        | while others return:
        |
        |     -48
        |
        | Both represent the same debit amount
        | for this benchmark.
        |
        */

        if (
            $this->isMoneyField($field) &&
            is_numeric($expected) &&
            is_numeric($actual)
        ) {

            return abs(
                abs((float) $expected) -
                abs((float) $actual)
            ) < 0.000001;
        }

        /*
        |--------------------------------------------------------------------------
        | Numeric values
        |--------------------------------------------------------------------------
        */

        if (
            is_numeric($expected) &&
            is_numeric($actual)
        ) {

            return abs(
                (float) $expected -
                (float) $actual
            ) < 0.000001;
        }

        /*
        |--------------------------------------------------------------------------
        | Boolean
        |--------------------------------------------------------------------------
        */

        if (
            is_bool($expected) ||
            is_bool($actual)
        ) {

            return
                (bool) $expected ===
                (bool) $actual;
        }

        /*
        |--------------------------------------------------------------------------
        | Arrays
        |--------------------------------------------------------------------------
        */

        if (
            is_array($expected) ||
            is_array($actual)
        ) {

            return $this->arraysEqual(
                $expected,
                $actual
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Strings
        |--------------------------------------------------------------------------
        */

        return $this->normalizeString(
            $expected
        ) ===
        $this->normalizeString(
            $actual
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Money field detection
    |--------------------------------------------------------------------------
    */

    protected function isMoneyField(
        ?string $field
    ): bool {

        return in_array(
            strtolower(
                (string) $field
            ),
            [
                'debit',
                'credit',
                'balance',
                'opening_balance',
                'closing_balance',
                'value',
                'amount',
            ],
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Date field detection
    |--------------------------------------------------------------------------
    */

    protected function isDateField(
        ?string $field
    ): bool {

        if ($field === null) {
            return false;
        }

        $field =
            strtolower($field);

        return
            $field === 'date' ||
            str_ends_with(
                $field,
                '_date'
            ) ||
            str_ends_with(
                $field,
                'date'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize strings
    |--------------------------------------------------------------------------
    */

    protected function normalizeString(
        mixed $value
    ): string {

        $value =
            trim(
                (string) $value
            );

        /*
         * Normalize multiple spaces.
         *
         * Example:
         *
         * "MONTHLY   MAINTENANCE"
         *
         * becomes:
         *
         * "MONTHLY MAINTENANCE"
         */
        $value =
            preg_replace(
                '/\s+/',
                ' ',
                $value
            );

        return
            mb_strtolower(
                trim($value)
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Compare arrays
    |--------------------------------------------------------------------------
    */

    protected function arraysEqual(
        mixed $expected,
        mixed $actual
    ): bool {

        if (
            !is_array($expected) ||
            !is_array($actual)
        ) {
            return false;
        }

        /*
         * Associative arrays.
         */
        if (
            $this->isAssociative($expected) ||
            $this->isAssociative($actual)
        ) {

            if (
                array_keys($expected) !==
                array_keys($actual)
            ) {
                return false;
            }

            foreach (
                $expected as $key => $value
            ) {

                if (
                    !$this->valuesEqual(
                        $value,
                        $actual[$key] ?? null,
                        $key
                    )
                ) {
                    return false;
                }
            }

            return true;
        }

        /*
         * Sequential arrays.
         */
        if (
            count($expected) !==
            count($actual)
        ) {
            return false;
        }

        foreach (
            $expected as $index => $value
        ) {

            if (
                !$this->valuesEqual(
                    $value,
                    $actual[$index] ?? null
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize dates
    |--------------------------------------------------------------------------
    */

    protected function normalizeDate(
        mixed $date
    ): ?string {

        if (
            $date === null ||
            trim((string) $date) === ''
        ) {
            return null;
        }

        $date =
            strtoupper(
                trim(
                    (string) $date
                )
            );

        $formats = [
            'Y-m-d',
            'dMY',
            'd-M-Y',
            'd/m/Y',
            'd/m/y',
            'Y/m/d',
        ];

        foreach (
            $formats as $format
        ) {

            $parsed =
                \DateTime::createFromFormat(
                    $format,
                    $date
                );

            if (
                $parsed !== false &&
                $parsed->format($format) ===
                    $date
            ) {

                return
                    $parsed->format(
                        'Y-m-d'
                    );
            }
        }

        return null;
    }
}
