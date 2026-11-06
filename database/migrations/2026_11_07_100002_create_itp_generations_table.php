<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of the pipeline for one intake. Server-only. `itp_id` points at the finished
 * itp-core `itps` row once the generation is complete.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itp_generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained();
            $table->foreignUlid('intake_id')->constrained('itp_intakes')->cascadeOnDelete();
            $table->string('status', 32)->default('queued')->index();
            $table->string('safety_route', 16)->nullable();
            $table->json('safety_categories')->nullable();
            $table->json('extraction')->nullable();
            $table->json('derived_slots')->nullable();
            $table->unsignedTinyInteger('followup_rounds')->default(0);
            $table->json('prompt_versions')->nullable();
            $table->foreignUlid('itp_id')->nullable()->constrained('itps')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itp_generations');
    }
};
