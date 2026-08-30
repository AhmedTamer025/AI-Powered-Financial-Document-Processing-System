<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | benchmark_runs
        |--------------------------------------------------------------------------
        */

        $runColumns = [
            'extraction_tokens',
            'extraction_cost',
            'normalization_tokens',
            'normalization_cost',
        ];

        Schema::table('benchmark_runs', function (Blueprint $table) use ($runColumns) {

            if (
                !in_array('extraction_tokens', $runColumns)
                || !Schema::hasColumn('benchmark_runs', 'extraction_tokens')
            ) {
                $table->unsignedBigInteger('extraction_tokens')
                    ->default(0)
                    ->after('total_tokens');
            }

            if (!Schema::hasColumn('benchmark_runs', 'extraction_cost')) {
                $table->decimal('extraction_cost', 12, 8)
                    ->default(0)
                    ->after('extraction_tokens');
            }

            if (!Schema::hasColumn('benchmark_runs', 'normalization_tokens')) {
                $table->unsignedBigInteger('normalization_tokens')
                    ->default(0)
                    ->after('extraction_cost');
            }

            if (!Schema::hasColumn('benchmark_runs', 'normalization_cost')) {
                $table->decimal('normalization_cost', 12, 8)
                    ->default(0)
                    ->after('normalization_tokens');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | benchmark_results
        |--------------------------------------------------------------------------
        */

        Schema::table('benchmark_results', function (Blueprint $table) {

            if (!Schema::hasColumn('benchmark_results', 'extraction_input_tokens')) {
                $table->unsignedBigInteger('extraction_input_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'extraction_output_tokens')) {
                $table->unsignedBigInteger('extraction_output_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'extraction_reasoning_tokens')) {
                $table->unsignedBigInteger('extraction_reasoning_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'extraction_total_tokens')) {
                $table->unsignedBigInteger('extraction_total_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'extraction_cost')) {
                $table->decimal('extraction_cost', 12, 8)
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'normalization_input_tokens')) {
                $table->unsignedBigInteger('normalization_input_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'normalization_output_tokens')) {
                $table->unsignedBigInteger('normalization_output_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'normalization_reasoning_tokens')) {
                $table->unsignedBigInteger('normalization_reasoning_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'normalization_total_tokens')) {
                $table->unsignedBigInteger('normalization_total_tokens')
                    ->default(0);
            }

            if (!Schema::hasColumn('benchmark_results', 'normalization_cost')) {
                $table->decimal('normalization_cost', 12, 8)
                    ->default(0);
            }
        });
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | benchmark_runs
        |--------------------------------------------------------------------------
        */

        $columns = [
            'extraction_tokens',
            'extraction_cost',
            'normalization_tokens',
            'normalization_cost',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('benchmark_runs', $column)) {
                Schema::table('benchmark_runs', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        /*
        |--------------------------------------------------------------------------
        | benchmark_results
        |--------------------------------------------------------------------------
        */

        $columns = [
            'extraction_input_tokens',
            'extraction_output_tokens',
            'extraction_reasoning_tokens',
            'extraction_total_tokens',
            'extraction_cost',
            'normalization_input_tokens',
            'normalization_output_tokens',
            'normalization_reasoning_tokens',
            'normalization_total_tokens',
            'normalization_cost',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('benchmark_results', $column)) {
                Schema::table('benchmark_results', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
