<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory, HasUuids;


    public $incrementing = false;

    protected $keyType = 'string';



    protected $fillable = [
        'name',
        'registration_number',
    ];



    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */


    public function bankStatements()
    {
        return $this->hasMany(
            BankStatement::class
        );
    }



    public function financialStatements()
    {
        return $this->hasMany(
            FinancialStatement::class
        );
    }



    public function aiResults()
    {
        return $this->hasMany(
            AiResult::class
        );
    }
    public function owners(): HasMany
    {
        return $this->hasMany(
            BusinessOwner::class
        );
    }
}
