<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_owners', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('name');

            $table->timestamp('created_at')->useCurrent();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_owners');
    }
};