<?php

namespace App\AI\DTOS;

final class NormalizedDocument
{
    public function __construct(

        // Detection
        public string $documentType,
        public float $overallConfidence,
        public array $warnings,
        public ?array $reportingPeriods,



        // Bank statement
        public ?array $bankStatement,

        // Financial Statements
        public ?array $balanceSheet,
        public ?array $incomeStatement,
        public ?array $cashFlowStatement,
        public ?array $equityStatement,

    ) {}
}
