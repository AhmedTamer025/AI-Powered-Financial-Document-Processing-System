<?php

namespace App\Enums;

enum DocumentStatus: int
{
    case Uploaded = 1;
    case Extracting = 2;
    case Extracted = 3;
    case Normalizing = 4;
    case Normalized = 5;
    case Saving = 6;
    case Completed = 7;
    case Failed = 8;

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'uploaded',
            self::Extracting => 'extracting',
            self::Extracted => 'extracted',
            self::Normalizing => 'normalizing',
            self::Normalized => 'normalized',
            self::Saving => 'saving',
            self::Completed => 'completed',
            self::Failed => 'failed',
        };
    }

    public static function fromLabel(string $label): self
    {
        return match (strtolower(trim($label))) {
            'uploaded' => self::Uploaded,
            'extracting' => self::Extracting,
            'extracted' => self::Extracted,
            'normalizing' => self::Normalizing,
            'normalized' => self::Normalized,
            'saving' => self::Saving,
            'completed' => self::Completed,
            'failed' => self::Failed,
            default => self::Uploaded,
        };
    }
}
