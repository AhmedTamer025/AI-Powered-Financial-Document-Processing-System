<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('benchmark_dataset_id')
                ->nullable()
                ->constrained('benchmark_datasets')
                ->nullOnDelete();

            $table->string('model');
            $table->string('provider');

            $table->string('status')
                ->default('running');

            $table->unsignedBigInteger('extraction_tokens')
                ->default(0);

            $table->decimal('extraction_cost', 12, 8)
                ->default(0);

            $table->unsignedBigInteger('normalization_tokens')
                ->default(0);

            $table->decimal('normalization_cost', 12, 8)
                ->default(0);

            $table->unsignedBigInteger('total_tokens')
                ->default(0);

            $table->decimal('total_cost', 12, 8)
                ->default(0);

            $table->unsignedInteger('processing_time_ms')
                ->nullable();

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('completed_at')
                ->nullable();

            $table->text('error')
                ->nullable();

            $table->timestamps();

            $table->index(
                [
                    'benchmark_dataset_id',
                    'model',
                ],
                'benchmark_run_dataset_model_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_runs');
    }
};
