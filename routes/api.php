<?php

use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\FinancialStatementAnalysisController;
use App\Http\Controllers\FinancialStatementController;
use App\Http\Controllers\NormalizeUploadedApiController;
use Illuminate\Support\Facades\Route;

Route::post(
    '/documents/prepare-upload',
    [DocumentUploadController::class, 'prepare']
);

Route::put(
    '/documents/upload/{reference}',
    [DocumentUploadController::class, 'upload']
)->name('documents.upload');

Route::post(
    '/documents/complete-upload',
    [DocumentUploadController::class, 'complete']
);

Route::post(
    '/document-normalizations/{aiResult}/normalize',
    [NormalizeUploadedApiController::class, 'normalize']
);

Route::get(
    '/documents/pending-normalization',
    [NormalizeUploadedApiController::class, 'pending']
);

Route::get(
    '/normalization/{normalization}',
    [NormalizeUploadedApiController::class, 'show']
);


Route::get(
    '/businesses/{businessId}/bank-transactions',
    [BankStatementController::class, 'transactions']
);
Route::get(
    'businesses/{businessId}/bank-statements/transactions/search',
    [BankStatementController::class, 'searchTransactions']
);

Route::get(
    '/businesses/{businessId}/financial-analysis',
    [FinancialStatementAnalysisController::class, 'analyze']
);
Route::get(
    '/businesses/{business}/financial-statements',
    [FinancialStatementController::class, 'index']
);