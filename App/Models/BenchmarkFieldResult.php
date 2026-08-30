<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenchmarkFieldResult extends Model
{
    use HasFactory;

    protected $table = 'benchmark_field_results';

    protected $fillable = [
        'benchmark_result_id',
        'field',
        'status',
        'expected_value',
        'actual_value',
    ];

    protected $casts = [
        'expected_value' => 'array',
        'actual_value' => 'array',
    ];

    public function result(): BelongsTo
    {
        return $this->belongsTo(
            BenchmarkResult::class,
            'benchmark_result_id'
        );
    }
}
