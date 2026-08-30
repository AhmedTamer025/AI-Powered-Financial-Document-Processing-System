<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessOwner extends Model
{
    protected $table = 'business_owners';

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'name',
    ];

    protected static function booted(): void
    {
        static::creating(function (BusinessOwner $owner) {
            if (! $owner->id) {
                $owner->id = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }
}