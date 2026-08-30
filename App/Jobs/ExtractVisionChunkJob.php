<?php

namespace App\Jobs;

use App\AI\Clients\VisionClient;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractVisionChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use Batchable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public string $path,
        public int $startPage,
        public int $endPage,
        public int $chunkIndex
    ) {}

    public function handle(
        VisionClient $visionClient
    ): void {

        //Limited concurrency
       //Only N Vision requests are allowed to run simultaneously.

        $maxConcurrent = max(
            1,
            (int) config(
                'ai.extraction.vision_parallel_jobs',
                3
            )
        );

        $lock = null;

        try {

           //Find an available Vision slot


            for ($slot = 1; $slot <= $maxConcurrent; $slot++) {

                $candidate = Cache::lock(
                    "vision-extraction-slot:{$slot}",
                    900
                );

                if ($candidate->get()) {
                    $lock = $candidate;
                    break;
                }
            }

            // No slot available


            if ($lock === null) {

                Log::info(
                    'Vision chunk waiting for available concurrency slot',
                    [
                        'batch_id' => $this->batchId,
                        'chunk' => $this->chunkIndex,
                        'range' =>
                            "{$this->startPage}-{$this->endPage}",
                    ]
                );

                // Release this job so another queue worker can execute
                //other chunks while we wait for a slot.

                $this->release(3);

                return;
            }

            //Execute Vision extraction


            $start = microtime(true);

            Log::info(
                'Vision chunk started',
                [
                    'batch_id' => $this->batchId,
                    'chunk' => $this->chunkIndex,
                    'range' =>
                        "{$this->startPage}-{$this->endPage}",
                ]
            );

            $result =
                $visionClient->extractVisionPageRangeForJob(
                    $this->path,
                    $this->startPage,
                    $this->endPage
                );

            //Save result


            $directory =
                "vision-batches/{$this->batchId}";

            Storage::disk('local')->makeDirectory(
                $directory
            );

            $resultPath =
                "{$directory}/chunk-{$this->chunkIndex}.json";

            Storage::disk('local')->put(
                $resultPath,
                json_encode(
                    $result,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRETTY_PRINT
                )
            );

            Log::info(
                'Vision chunk finished',
                [
                    'batch_id' => $this->batchId,
                    'chunk' => $this->chunkIndex,
                    'range' =>
                        "{$this->startPage}-{$this->endPage}",
                    'seconds' =>
                        round(
                            microtime(true) - $start,
                            2
                        ),
                ]
            );

        } catch (Throwable $e) {

            Log::error(
                'Vision chunk failed',
                [
                    'batch_id' => $this->batchId,
                    'chunk' => $this->chunkIndex,
                    'range' =>
                        "{$this->startPage}-{$this->endPage}",
                    'error' => $e->getMessage(),
                ]
            );

            throw $e;

        } finally {

            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // Do not hide the original exception.
                }
            }
        }
    }
}
