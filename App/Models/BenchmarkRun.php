<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenchmarkRun extends Model
{
    protected $fillable = [
        'benchmark_dataset_id',
        'model',
        'provider',
        'status',

        'extraction_tokens',
        'extraction_cost',

        'normalization_tokens',
        'normalization_cost',

        'total_tokens',
        'total_cost',

        'processing_time_ms',

        'started_at',
        'completed_at',

        'error',
    ];

    protected $casts = [
        'benchmark_dataset_id' => 'integer',

        'extraction_tokens' => 'integer',
        'extraction_cost' => 'decimal:8',

        'normalization_tokens' => 'integer',
        'normalization_cost' => 'decimal:8',

        'total_tokens' => 'integer',
        'total_cost' => 'decimal:8',

        'processing_time_ms' => 'integer',

        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(
            BenchmarkResult::class
        );
    }

    public function dataset()
    {
        return $this->belongsTo(
            BenchmarkDataset::class,
            'benchmark_dataset_id'
        );
    }
}
