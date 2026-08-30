<?php

namespace App\Jobs;

use App\AI\DTOS\RawDocument;
use App\AI\Services\AiResultArchiveService;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\AiResult;
use App\Models\BankStatement;
use App\Models\BenchmarkResult;
use App\Models\DocumentNormalization;
use App\Models\FinancialStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessUploadedDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(

        public string $businessId,

        public string $storedPath,

        public string $originalName,

        public string $expectedType,

        public RawDocument $rawDocument,

        public array $extractionUsage = []

    ) {}

    public function handle(AiResultArchiveService $archiver)
    {
        DB::transaction(function () use ($archiver) {

            $absolutePath = Storage::disk('local')->path($this->storedPath);

            /*
            |--------------------------------------------------------------------------
            | Verify Uploaded File Exists
            |--------------------------------------------------------------------------
            */

            if (!Storage::disk('local')->exists($this->storedPath)) {


                throw new \RuntimeException(
                    "Uploaded file not found: {$this->storedPath}"
                );
            }


            //Create AiResult first (without raw_extraction) to get its UUID


            $aiResult = AiResult::create([
                'provider'      => config('ai.extraction.provider', 'mistral'),
                'model'         => config('ai.extraction.mode', 'ocr') === 'vision'
                    ? config('ai.extraction.vision_model', config('ai.extraction.model', 'mistral-large-latest'))
                    : config('ai.extraction.ocr_model', config('ai.extraction.model', 'mistral-ocr-latest')),

                'document_type' =>
                    DocumentType::fromLabel($this->expectedType),

                'status' =>
                    DocumentStatus::Uploaded,
            ]);

            //Archive raw extraction to disk, store only the path in DB

            BenchmarkResult::create([
                'ai_result_id' => $aiResult->id,
                'stage' => 'extraction',
                'model' => $document->metadata['model'] ?? $aiResult->model,
                'provider' => $document->metadata['provider'] ?? $aiResult->provider,
                'source_type' => $document->metadata['mode'] ?? config('ai.extraction.mode', 'ocr'),
                'processing_time_ms' => null,
                'tokens_used' => $extractionUsage['total_tokens'] ?? null,
                'cost' => $extractionUsage['cost'] ?? null,
            ]);

            $rawExtractionPath = $archiver->store(
                $aiResult->id,
                'raw_extraction',
                [
                    'file_name'  => $this->rawDocument->fileName,
                    'extension'  => $this->rawDocument->extension,
                    'file_path'  => $this->rawDocument->filePath,
                    'file_size'  => $this->rawDocument->fileSize,
                    'plain_text' => $this->rawDocument->plainText,
                    'sections'   => $this->rawDocument->sections,
                    'tables'     => $this->rawDocument->tables,
                    'metadata'   => $this->rawDocument->metadata,
                    'warnings'   => $this->rawDocument->warnings,
                ]
            );

            $aiResult->update([
                'raw_extraction' => $rawExtractionPath,
            ]);

            $data = [

                'business_id' => $this->businessId,

                'ai_result_id' => $aiResult->id,

                'original_file_name' => $this->originalName,

                'stored_file_name' => basename($this->storedPath),

                'stored_path' => $this->storedPath,

                'extension' => pathinfo($this->storedPath, PATHINFO_EXTENSION),

                'mime_type' => mime_content_type($absolutePath),

                'size' => Storage::size($this->storedPath),

            ];

            return match (DocumentType::fromLabel($this->expectedType)) {

                DocumentType::BankStatement =>
                    BankStatement::create($data),

                DocumentType::FinancialStatement =>
                    FinancialStatement::create($data),

                default =>
                    throw new \RuntimeException('Unsupported document type'),
            };
        });
    }



    public function failed(Throwable $e): void
    {
        Log::error('ProcessUploadedDocumentJob failed', [

            'file' => $this->originalName,

            'error' => $e->getMessage(),

        ]);
    }
}
