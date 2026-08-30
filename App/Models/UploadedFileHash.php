<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadedFileHash extends Model
{
    protected $fillable = [
        'file_hash',
        'reference',
        'stored_path',
        'original_file_name',
    ];
}