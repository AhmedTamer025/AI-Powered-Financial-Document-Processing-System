<?php

namespace App\AI\DTOS;

final class NormalizedValue
{
    public function __construct(
        public readonly mixed $value,
        public readonly ?SourceLocation $source = null,
    ) {}

    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'source' => $this->source?->toArray(),
        ];
    }
}
