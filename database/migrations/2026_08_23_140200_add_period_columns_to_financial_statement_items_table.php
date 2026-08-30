<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_statement_items', function (Blueprint $table) {

            if (! Schema::hasColumn('financial_statement_items', 'period_key')) {
                $table->string('period_key')
                    ->nullable()
                    ->after('label');
            }

            if (! Schema::hasColumn('financial_statement_items', 'period_date')) {
                $table->date('period_date')
                    ->nullable()
                    ->after('period_key');
            }

            if (! Schema::hasColumn('financial_statement_items', 'period_type')) {
                $table->string('period_type')
                    ->nullable()
                    ->after('period_date');
            }
        });

        $indexNames = collect(Schema::getIndexes('financial_statement_items'))
            ->pluck('name')
            ->all();

        if (! in_array('financial_statement_items_period_unique', $indexNames, true)) {
            Schema::table('financial_statement_items', function (Blueprint $table) {
                $table->unique(
                    [
                        'financial_statement_id',
                        'period_key',
                        'category',
                        'name',
                    ],
                    'financial_statement_items_period_unique'
                );
            });
        }
    }

    public function down(): void
    {
        $indexNames = collect(Schema::getIndexes('financial_statement_items'))
            ->pluck('name')
            ->all();

        Schema::table('financial_statement_items', function (Blueprint $table) use ($indexNames) {

            if (in_array('financial_statement_items_period_unique', $indexNames, true)) {
                $table->dropUnique('financial_statement_items_period_unique');
            }

            $columns = [];

            foreach (['period_key', 'period_date', 'period_type'] as $column) {
                if (Schema::hasColumn('financial_statement_items', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
