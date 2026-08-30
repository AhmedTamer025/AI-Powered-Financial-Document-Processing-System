<?php

namespace App\Models;

use App\Enums\BankStatementTransaction as BankStatementTransactionEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class BankStatementTransaction extends Model
{
    use HasUuids;

    protected $table = 'bank_statement_transactions';

    protected $fillable = [
        'bank_statement_id',
        'posting_date',
        'value_date',
        'description',
        'reference',
        'counterparty',
        'category',
        'type',
        'other_transaction',
        'debit',
        'credit',
        'balance',
        'confidence',
        'source',
    ];

    protected $casts = [
        'posting_date' => 'date',
        'value_date' => 'date',
        'type' => BankStatementTransactionEnum::class,
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
        'confidence' => 'decimal:2',
        'source' => 'array',
    ];

    public function bankStatement()
    {
        return $this->belongsTo(
            BankStatement::class
        );
    }
}
