<?php

use App\Enums\BankStatementTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_transactions', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Relations
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('bank_statement_id')
                ->constrained('bank_statements')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Transaction Information
            |--------------------------------------------------------------------------
            */

            $table->date('posting_date')->nullable();

            $table->date('value_date')->nullable();

            $table->text('description')->nullable();

            $table->string('reference')->nullable();

            $table->string('counterparty')->nullable();

            $table->string('category')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Transaction Type
            |--------------------------------------------------------------------------
            |
            | Stored as integer in database.
            | Cast to BankStatementTransaction enum in the model.
            |
            */

            $table->unsignedTinyInteger('type')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Unknown Transaction
            |--------------------------------------------------------------------------
            |
            | If AI returns an unsupported transaction type:
            |
            | type = BankStatementTransaction::Other->value
            | other_transaction = original AI value
            |
            */

            $table->text('other_transaction')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Amounts
            |--------------------------------------------------------------------------
            */

            $table->decimal('debit', 15, 2)->nullable();

            $table->decimal('credit', 15, 2)->nullable();

            $table->decimal('balance', 15, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI
            |--------------------------------------------------------------------------
            */

            $table->decimal('confidence', 5, 2)->nullable();

            $table->json('source')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index('bank_statement_id');

            $table->index('posting_date');

            $table->index('reference');

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_transactions');
    }
};
