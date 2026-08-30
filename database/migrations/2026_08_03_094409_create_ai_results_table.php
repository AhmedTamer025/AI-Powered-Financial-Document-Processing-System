<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_results', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | AI Information
            |--------------------------------------------------------------------------
            */

            $table->string('provider');
            $table->string('model');

            /*
            |--------------------------------------------------------------------------
            | Document
            |--------------------------------------------------------------------------
            */

            $table->unsignedTinyInteger('document_type');

            /*
            |--------------------------------------------------------------------------
            | OCR / Extraction
            |--------------------------------------------------------------------------
            */

            $table->longText('raw_extraction')->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Output
            |--------------------------------------------------------------------------
            */

            $table->longText('normalized_result')->nullable();

            $table->decimal(
                'overall_confidence',
                5,
                2
            )->nullable();

            $table->json('warnings')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Pipeline
            |--------------------------------------------------------------------------
            */

            $table->unsignedTinyInteger('status')
                ->default(1);

            $table->text('error_message')
                ->nullable();

            $table->unsignedInteger('processing_time_ms')
                ->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_results');
    }
};
