<?php

namespace App\AI\DTOS;

final class SourceLocation
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?int $page = null,
        public readonly ?string $sheet = null,
        public readonly ?int $row = null,
        public readonly ?string $cell = null,
        public readonly ?int $section = null,
        public readonly ?int $table = null,
        public readonly ?int $block = null,
        public readonly ?string $label = null,
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        return new self(
            type: $data['type'] ?? null,
            page: $data['page'] ?? null,
            sheet: $data['sheet'] ?? null,
            row: $data['row'] ?? null,
            cell: $data['cell'] ?? null,
            section: $data['section'] ?? null,
            table: $data['table'] ?? null,
            block: $data['block'] ?? null,
            label: $data['label'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'page' => $this->page,
            'sheet' => $this->sheet,
            'row' => $this->row,
            'cell' => $this->cell,
            'section' => $this->section,
            'table' => $this->table,
            'block' => $this->block,
            'label' => $this->label,
        ], static fn ($value): bool => $value !== null);
    }
}
