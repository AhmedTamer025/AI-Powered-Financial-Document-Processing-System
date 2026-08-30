<?php

namespace App\AI\Benchmark;

use App\AI\Agents\BankStatementNormalizationAgent;
use App\AI\Agents\FinancialStatementNormalizationAgent;
use RuntimeException;

class BenchmarkModelRouter
{
    /**
     * Resolve the correct normalization agent
     * according to the selected model and document type.
     */
    public function resolve(
        string $model,
        string $documentType
    ): object {
        return match ($model) {
            'mistral' => $this->resolveMistral($documentType),

            'gemini' => $this->resolveGemini($documentType),

            default => throw new RuntimeException(
                "Unsupported benchmark model [{$model}]."
            ),
        };
    }

    /**
     * Resolve existing Mistral production agents.
     */
    protected function resolveMistral(
        string $documentType
    ): object {
        return match ($documentType) {
            'bank_statement' =>
                app(BankStatementNormalizationAgent::class),

            'financial_statement' =>
                app(FinancialStatementNormalizationAgent::class),

            default => throw new RuntimeException(
                "Unsupported document type [{$documentType}] for Mistral."
            ),
        };
    }

    /**
     * Resolve Gemini benchmark agents.
     */
    protected function resolveGemini(
        string $documentType
    ): object {
        return match ($documentType) {
            'bank_statement' =>
                app(GeminiBankStatementAgent::class),

            'financial_statement' =>
                app(GeminiFinancialStatementAgent::class),

            default => throw new RuntimeException(
                "Unsupported document type [{$documentType}] for Gemini."
            ),
        };
    }
}
