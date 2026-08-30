<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_statement_items', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Relation
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('financial_statement_id')
                ->constrained('financial_statements')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Financial Statement Category
            |--------------------------------------------------------------------------
            |
            | Stored as integer in database.
            | Cast to FinancialStatementCategory enum in the model.
            |
            */

            $table->unsignedTinyInteger('category');

            /*
            |--------------------------------------------------------------------------
            | Financial Statement Item
            |--------------------------------------------------------------------------
            |
            | Stored as integer in database.
            | Cast to FinancialStatementItem enum in the model.
            |
            */

            $table->unsignedTinyInteger('name');

            /*
            |--------------------------------------------------------------------------
            | Original / Display Label
            |--------------------------------------------------------------------------
            */

            $table->string('label')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Value
            |--------------------------------------------------------------------------
            */

            $table->decimal(
                'amount',
                15,
                2
            )->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI
            |--------------------------------------------------------------------------
            */

            $table->decimal(
                'confidence',
                5,
                2
            )->nullable();

            $table->json('metadata')
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

            $table->index('financial_statement_id');

            $table->index([
                'category',
                'name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'financial_statement_items'
        );
    }
};
