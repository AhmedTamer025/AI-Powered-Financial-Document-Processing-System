<?php

namespace App\Http\Controllers;

use App\Models\AiResult;
use Illuminate\Support\Facades\Storage;

class AiResultFileController extends Controller
{
    protected string $disk = 'local';

    protected string $baseFolder = 'ai_results';

    public function show(AiResult $aiResult, string $type)
    {
        $path = "{$this->baseFolder}/{$aiResult->id}/{$type}.txt";

        if (! Storage::disk($this->disk)->exists($path)) {

            abort(404, 'Archived file not found.');
        }

        $absolutePath = Storage::disk($this->disk)->path($path);

        return response()->file(

            $absolutePath,

            [
                'Content-Type' => 'application/json',
            ]

        );
    }
}