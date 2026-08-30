<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_dataset_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('benchmark_dataset_id')
                ->constrained('benchmark_datasets')
                ->cascadeOnDelete();

            $table->string('filename');

            $table->text('path');

            $table->string('document_type');

            $table->json('metadata')
                ->nullable();

            $table->json('ground_truth')
                ->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'benchmark_dataset_id',
                    'filename',
                ],
                'benchmark_dataset_file_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_dataset_files');
    }
};
