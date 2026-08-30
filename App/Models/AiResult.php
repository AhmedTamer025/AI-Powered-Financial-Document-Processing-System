<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AiResult extends Model
{
    use HasUuids;

    protected $table = 'ai_results';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'provider',
        'model',
        'document_type',
        'status',
        'raw_extraction',
        'normalized_result',
        'overall_confidence',
        'warnings',
        'processing_time_ms',
        'error_message',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'status' => DocumentStatus::class,
        'warnings' => 'array',
        'overall_confidence' => 'decimal:2',
        'processing_time_ms' => 'integer',
    ];

    public function benchmarkResults(): HasMany
    {
        return $this->hasMany(
            BenchmarkResult::class,
            'ai_result_id'
        );
    }

    public function benchmarkExtraction()
    {
        return $this->benchmarkResults()
            ->where('stage', 'extraction');
    }

    public function benchmarkNormalization()
    {
        return $this->benchmarkResults()
            ->where('stage', 'normalization');
    }
}
