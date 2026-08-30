<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenchmarkDatasetFile extends Model
{
    use HasFactory;

    protected $table = 'benchmark_dataset_files';

    protected $fillable = [
        'benchmark_dataset_id',
        'filename',
        'path',
        'document_type',
        'metadata',
        'ground_truth',
    ];

    protected $casts = [
        'metadata' => 'array',
        'ground_truth' => 'array',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(
            BenchmarkDataset::class,
            'benchmark_dataset_id'
        );
    }

    public function results(): HasMany
    {
        return $this->hasMany(
            BenchmarkResult::class,
            'benchmark_dataset_file_id'
        );
    }
}
