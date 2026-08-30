<?php

namespace App\AI\Services;

use Illuminate\Support\Facades\Storage;

class AiResultArchiveService
{
    protected string $disk = 'local';

    protected string $baseFolder = 'ai_results';

    /**
     * Compute the internal storage path (never exposed directly).
     */
    protected function path(string $reference, string $key): string
    {
        return "{$this->baseFolder}/{$reference}/{$key}.txt";
    }

    /**
     * Store content as a text file and return a clickable URL (for DB storage).
     */
    public function store(string $reference, string $key, mixed $content): string
    {
        $path = $this->path($reference, $key);

        $body = is_string($content)
            ? $content
            : json_encode(
                $content,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

        Storage::disk($this->disk)->put($path, $body ?? '');

        return route('ai-results.file', [
            'aiResult' => $reference,
            'type'     => $key,
        ]);
    }

    /**
     * Read back the archived content directly, regardless of what's stored in DB.
     */
    public function getContent(string $reference, string $key): ?string
    {
        $path = $this->path($reference, $key);

        return Storage::disk($this->disk)->exists($path)
            ? Storage::disk($this->disk)->get($path)
            : null;
    }
}