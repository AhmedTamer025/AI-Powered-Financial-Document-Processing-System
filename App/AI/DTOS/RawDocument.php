<?php

namespace App\AI\DTOS;

final  class RawDocument
{
    public function __construct(

      
        public string $fileName,
        public string $extension,
        public string $filePath,
        public int $fileSize,
        public string $plainText,
        public array $sections,
        public array $tables,
        public array $metadata = [],
        public array $warnings = [],
    ) {}
}