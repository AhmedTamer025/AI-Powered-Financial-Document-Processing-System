<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uploaded_file_hashes', function (Blueprint $table) {
            $table->id();

            $table->string('file_hash', 64)->unique();

            $table->string('reference', 500)->unique();

            $table->string('stored_path', 1000);

            $table->string('original_file_name', 500)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploaded_file_hashes');
    }
};
