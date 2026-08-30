<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_datasets', function (Blueprint $table) {
            $table->id();

            $table->string('dataset_id');

            $table->string('version')
                ->default('v1');

            $table->string('name');

            $table->text('description')
                ->nullable();

            $table->string('document_type');

            $table->string('status')
                ->default('active');

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->unique(
                ['dataset_id', 'version'],
                'benchmark_dataset_id_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_datasets');
    }
};
