<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_transactions', function (Blueprint $table) {

            if (!Schema::hasColumn('bank_statement_transactions', 'source')) {

                $table->json('source')
                    ->nullable()
                    ->after('confidence');

            }

        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_transactions', function (Blueprint $table) {

            if (Schema::hasColumn('bank_statement_transactions', 'source')) {

                $table->dropColumn('source');

            }

        });
    }
};
