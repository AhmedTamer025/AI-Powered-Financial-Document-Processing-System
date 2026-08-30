<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('benchmark_run_id')
                ->nullable()
                ->constrained('benchmark_runs')
                ->nullOnDelete();

            $table->foreignUuid('ai_result_id')
                ->nullable()
                ->constrained('ai_results')
                ->nullOnDelete();

            $table->foreignId('benchmark_dataset_file_id')
                ->nullable()
                ->constrained('benchmark_dataset_files')
                ->nullOnDelete();

            $table->string('status')
                ->default('completed');

            $table->string('stage')
                ->nullable();

            $table->string('model')
                ->nullable();

            $table->string('provider')
                ->nullable();

            $table->string('source_type')
                ->nullable();

            $table->json('prediction')
                ->nullable();

            $table->json('evaluation')
                ->nullable();

            $table->decimal('accuracy', 8, 4)
                ->nullable();

            $table->unsignedInteger('correct_fields')
                ->default(0);

            $table->unsignedInteger('incorrect_fields')
                ->default(0);

            $table->unsignedInteger('missing_fields')
                ->default(0);

            $table->unsignedInteger('extra_fields')
                ->default(0);

            $table->unsignedBigInteger('extraction_input_tokens')
                ->default(0);

            $table->unsignedBigInteger('extraction_output_tokens')
                ->default(0);

            $table->unsignedBigInteger('extraction_reasoning_tokens')
                ->default(0);

            $table->unsignedBigInteger('extraction_total_tokens')
                ->default(0);

            $table->decimal('extraction_cost', 12, 8)
                ->default(0);

            $table->unsignedBigInteger('normalization_input_tokens')
                ->default(0);

            $table->unsignedBigInteger('normalization_output_tokens')
                ->default(0);

            $table->unsignedBigInteger('normalization_reasoning_tokens')
                ->default(0);

            $table->unsignedBigInteger('normalization_total_tokens')
                ->default(0);

            $table->decimal('normalization_cost', 12, 8)
                ->default(0);

            $table->unsignedBigInteger('total_tokens')
                ->default(0);

            $table->decimal('total_cost', 12, 8)
                ->default(0);

            $table->unsignedInteger('processing_time_ms')
                ->nullable();

            $table->text('error')
                ->nullable();

            $table->timestamps();

            $table->index(
                [
                    'ai_result_id',
                    'stage',
                ],
                'benchmark_result_ai_result_stage_index'
            );

            $table->index(
                [
                    'benchmark_run_id',
                    'benchmark_dataset_file_id',
                ],
                'benchmark_result_run_file_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_results');
    }
};
