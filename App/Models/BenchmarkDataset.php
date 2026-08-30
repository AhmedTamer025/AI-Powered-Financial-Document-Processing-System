<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenchmarkDataset extends Model
{
    use HasFactory;

    protected $table = 'benchmark_datasets';

    protected $fillable = [
        'dataset_id',
        'version',
        'name',
        'description',
        'document_type',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(
            BenchmarkDatasetFile::class,
            'benchmark_dataset_id'
        );
    }

    public function runs(): HasMany
    {
        return $this->hasMany(
            BenchmarkRun::class,
            'benchmark_dataset_id'
        );
    }
}
