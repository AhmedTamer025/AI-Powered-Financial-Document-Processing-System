<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenchmarkResult extends Model
{
    protected $fillable = [

        'benchmark_run_id',
        'ai_result_id',
        'benchmark_dataset_file_id',

        'status',
        'stage',

        'model',
        'provider',
        'source_type',

        'prediction',
        'evaluation',

        'accuracy',
        'correct_fields',
        'incorrect_fields',
        'missing_fields',
        'extra_fields',

        /*
        |--------------------------------------------------------------------------
        | Extraction Usage
        |--------------------------------------------------------------------------
        */

        'extraction_input_tokens',
        'extraction_output_tokens',
        'extraction_reasoning_tokens',
        'extraction_total_tokens',
        'extraction_cost',

        /*
        |--------------------------------------------------------------------------
        | Normalization Usage
        |--------------------------------------------------------------------------
        */

        'normalization_input_tokens',
        'normalization_output_tokens',
        'normalization_reasoning_tokens',
        'normalization_total_tokens',
        'normalization_cost',

        /*
        |--------------------------------------------------------------------------
        | Overall Usage
        |--------------------------------------------------------------------------
        */

        'total_tokens',
        'total_cost',

        /*
        |--------------------------------------------------------------------------
        | Processing
        |--------------------------------------------------------------------------
        */

        'processing_time_ms',

        'error',
    ];

    protected $casts = [

        'benchmark_run_id' =>
            'integer',

        'benchmark_dataset_file_id' =>
            'integer',

        'prediction' =>
            'array',

        'evaluation' =>
            'array',

        'accuracy' =>
            'decimal:4',

        'correct_fields' =>
            'integer',

        'incorrect_fields' =>
            'integer',

        'missing_fields' =>
            'integer',

        'extra_fields' =>
            'integer',

        /*
        |--------------------------------------------------------------------------
        | Extraction
        |--------------------------------------------------------------------------
        */

        'extraction_input_tokens' =>
            'integer',

        'extraction_output_tokens' =>
            'integer',

        'extraction_reasoning_tokens' =>
            'integer',

        'extraction_total_tokens' =>
            'integer',

        'extraction_cost' =>
            'decimal:8',

        /*
        |--------------------------------------------------------------------------
        | Normalization
        |--------------------------------------------------------------------------
        */

        'normalization_input_tokens' =>
            'integer',

        'normalization_output_tokens' =>
            'integer',

        'normalization_reasoning_tokens' =>
            'integer',

        'normalization_total_tokens' =>
            'integer',

        'normalization_cost' =>
            'decimal:8',

        /*
        |--------------------------------------------------------------------------
        | Overall
        |--------------------------------------------------------------------------
        */

        'total_tokens' =>
            'integer',

        'total_cost' =>
            'decimal:8',

        /*
        |--------------------------------------------------------------------------
        | Processing
        |--------------------------------------------------------------------------
        */

        'processing_time_ms' =>
            'integer',
    ];

    public function benchmarkRun(): BelongsTo
    {
        return $this->belongsTo(
            BenchmarkRun::class
        );
    }

    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(
            AiResult::class
        );
    }

    public function datasetFile(): BelongsTo
    {
        return $this->belongsTo(
            BenchmarkDatasetFile::class,
            'benchmark_dataset_file_id'
        );
    }
}
