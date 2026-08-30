<?php

namespace App\AI\Services;

class Utf8Sanitizer
{
    public static function clean(string $value): string
    {
        // Remove invalid UTF8 bytes
        $value = mb_convert_encoding(
            $value,
            'UTF-8',
            'UTF-8'
        );

        // Remove remaining invalid characters
        $value = preg_replace(
            '/[^\x{0000}-\x{FFFF}]/u',
            '',
            $value
        );

        return $value ?? '';
    }
}