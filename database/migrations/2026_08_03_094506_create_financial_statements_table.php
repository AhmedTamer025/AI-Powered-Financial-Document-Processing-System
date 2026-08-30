<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_statements', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Relations
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignUuid('ai_result_id')
                ->constrained('ai_results')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | File Information
            |--------------------------------------------------------------------------
            */

            $table->string('original_file_name');

            $table->string('stored_file_name');

            $table->string('stored_path');

            $table->string('extension', 20);

            $table->string('mime_type');

            $table->unsignedBigInteger('size');

            /*
            |--------------------------------------------------------------------------
            | Statement Information
            |--------------------------------------------------------------------------
            */

            $table->string('statement_type')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Reporting Period
            |--------------------------------------------------------------------------
            |
            | Stored as integer in database.
            | Cast to TransactionPeriod enum in the model.
            |
            */

            $table->unsignedTinyInteger('period_type')
                ->nullable();

            $table->date('date')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index('business_id');

            $table->index('ai_result_id');

            $table->index('statement_type');

            $table->index('period_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_statements');
    }
};
