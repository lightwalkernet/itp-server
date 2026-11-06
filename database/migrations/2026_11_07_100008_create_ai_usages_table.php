<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AI call: tokens, model, prompt version, duration and cost in USD. The cost
 * record outlives its generation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('generation_id')->nullable()->constrained('itp_generations')->nullOnDelete();
            $table->string('stage', 32);
            $table->string('provider', 32)->default('anthropic');
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6);
            $table->foreignId('prompt_version_id')->nullable()->constrained('prompt_versions')->nullOnDelete();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['stage', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
