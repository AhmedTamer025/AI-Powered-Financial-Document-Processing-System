<?php

namespace App\Models;

use App\Enums\TransactionPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FinancialStatement extends Model
{
    use HasUuids;

    protected $table = 'financial_statements';

    protected $fillable = [
        'business_id',
        'ai_result_id',
        'original_file_name',
        'stored_file_name',
        'stored_path',
        'extension',
        'mime_type',
        'size',
        'statement_type',
        'period_type',
        'date',
    ];

    protected $casts = [
        'period_type' => TransactionPeriod::class,
        'date' => 'date',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function aiResult()
    {
        return $this->belongsTo(AiResult::class);
    }

    public function items()
    {
        return $this->hasMany(
            FinancialStatementItem::class
        );
    }
}
