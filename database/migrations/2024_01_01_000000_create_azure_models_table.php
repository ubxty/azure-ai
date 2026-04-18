<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('azure_models', function (Blueprint $table) {
            $table->string('model_id')->primary();
            $table->string('name');
            $table->string('provider');
            $table->string('connection')->default('default')->index();
            $table->unsignedInteger('context_window')->default(0);
            $table->unsignedInteger('max_tokens')->default(0);
            $table->json('capabilities');
            $table->json('input_modalities')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('lifecycle_status')->default('ACTIVE');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azure_models');
    }
};
