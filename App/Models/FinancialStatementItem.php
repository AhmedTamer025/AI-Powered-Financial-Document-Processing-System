<?php

namespace App\Models;

use App\Enums\FinancialStatementCategory;
use App\Enums\FinancialStatementItem as FinancialStatementItemEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FinancialStatementItem extends Model
{
    use HasUuids;

    protected $table = 'financial_statement_items';

    protected $fillable = [
        'financial_statement_id',
        'category',
        'name',
        'label',
        'period_key',
        'period_date',
        'period_type',

        'amount',
        'confidence',
        'metadata',
    ];

    protected $casts = [
        'category' => FinancialStatementCategory::class,
        'name' => FinancialStatementItemEnum::class,
        'period_date' => 'date',
        'amount' => 'decimal:2',
        'confidence' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function financialStatement()
    {
        return $this->belongsTo(
            FinancialStatement::class
        );
    }
}