<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class BankStatement extends Model
{
    use HasUuids;

    protected $table = 'bank_statements';

    protected $fillable = [

        'business_id',

        'ai_result_id',

        'original_file_name',

        'stored_file_name',

        'stored_path',

        'extension',

        'mime_type',

        'size',

        'bank_name',

        'branch',

        'account_holder',

        'account_number',

        'iban',

        'currency',

        'statement_from',

        'statement_to',

        'opening_balance',

        'closing_balance',

    ];

    protected $casts = [

        'statement_from' => 'date',

        'statement_to' => 'date',

        'opening_balance' => 'decimal:2',

        'closing_balance' => 'decimal:2',

    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function business()
    {
        return $this->belongsTo(
            Business::class
        );
    }

    public function aiResult()
    {
        return $this->belongsTo(
            AiResult::class
        );
    }

    public function transactions()
    {
        return $this->hasMany(
            BankStatementTransaction::class
        );
    }
}