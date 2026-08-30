<?php

namespace App\AI\Services;

final class SourceNormalizationService
{
    public function normalizeSource(?array $source): ?array
    {
        if ($source === null || !is_array($source)) {
            return null;
        }

        if ($source === []) {
            return ['type' => 'pdf'];
        }

        if (!isset($source['type']) || $source['type'] === null) {
            $source['type'] = $this->inferType($source);
        }

        if (($source['type'] ?? null) === null) {
            $source['type'] = 'pdf';
        }

        if (empty($source['page']) && !empty($source['pages']) && is_array($source['pages']) && count($source['pages']) === 1) {
            $source['page'] = $source['pages'][0];
        }

        if (empty($source['page']) && !empty($source['page_number'])) {
            $source['page'] = $source['page_number'];
        }

        if (empty($source['page']) && !empty($source['pageNo'])) {
            $source['page'] = $source['pageNo'];
        }

        $allowedKeys = $this->allowedKeys($source['type'], $source);

        $normalized = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null) {
                $normalized[$key] = $source[$key];
            }
        }

        foreach (['page', 'row', 'table', 'sheet', 'cell', 'section', 'block', 'label'] as $key) {
            if (!array_key_exists($key, $normalized) && array_key_exists($key, $source) && $source[$key] !== null) {
                $normalized[$key] = $source[$key];
            }
        }

        if ($normalized === [] && !empty($source['page'])) {
            $normalized = ['type' => $source['type'], 'page' => $source['page']];
        }

        return $normalized === [] ? null : $normalized;
    }

    private function inferType(array $source): ?string
    {
        $type = strtolower((string) ($source['type'] ?? ''));

        if (in_array($type, ['xlsx', 'xls', 'csv', 'ods', 'excel'], true)) {
            return 'excel';
        }

        if (in_array($type, ['pdf', 'ocr', 'image', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            return 'pdf';
        }

        if (str_contains($type, 'word') || str_contains($type, 'doc')) {
            return 'word';
        }

        if (!empty($source['sheet']) || !empty($source['cell'])) {
            return 'excel';
        }

        if (!empty($source['page'])) {
            return 'pdf';
        }

        if (!empty($source['section']) || !empty($source['table'])) {
            return 'word';
        }

        return null;
    }

    private function allowedKeys(?string $type, array $source): array
    {
        if ($type === 'excel') {
            return ['type', 'sheet', 'row', 'cell', 'label'];
        }

        if ($type === 'pdf' || $type === 'ocr' || $type === 'image') {
            return ['type', 'page', 'row', 'label'];
        }

        if ($type === 'word') {
            return ['type', 'section', 'table', 'row', 'label'];
        }

        return array_values(array_intersect(
            ['type', 'page', 'sheet', 'row', 'cell', 'section', 'table', 'block', 'label'], 
            array_keys($source)
        ));
    }
}
