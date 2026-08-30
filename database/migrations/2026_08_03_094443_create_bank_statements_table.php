<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {

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

            $table->string('extension',20);

            $table->string('mime_type');

            $table->unsignedBigInteger('size');

            /*
            |--------------------------------------------------------------------------
            | Bank Information
            |--------------------------------------------------------------------------
            */

            $table->string('bank_name')->nullable();

            $table->string('branch')->nullable();

            $table->string('account_holder')->nullable();

            $table->string('account_number')->nullable();

            $table->string('iban')->nullable();

            $table->string('currency')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Statement
            |--------------------------------------------------------------------------
            */

            $table->date('statement_from')->nullable();

            $table->date('statement_to')->nullable();

            $table->decimal('opening_balance',15,2)->nullable();

            $table->decimal('closing_balance',15,2)->nullable();

            $table->timestamps();

            $table->index('business_id');
            $table->index('ai_result_id');
            $table->index([
                'business_id',
                'statement_from',
                'statement_to'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};