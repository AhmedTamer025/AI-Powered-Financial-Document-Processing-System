<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\AiResultFileController;
use Illuminate\Support\Facades\Route;


Route::get('/upload',
    [FileController::class,'index']
)
->name('upload.form');


Route::post('/upload',
    [FileController::class,'upload']
)
->name('upload');


Route::get('/documents/status/{batchReference}',
    [FileController::class,'status']
)
->name('documents.status');


Route::get('/ai-results/{aiResult}/files/{type}',
    [AiResultFileController::class, 'show']
)
->whereIn('type', ['raw_extraction', 'normalized_result'])
->name('ai-results.file');