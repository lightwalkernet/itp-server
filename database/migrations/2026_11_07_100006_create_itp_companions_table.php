<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Breathe 11 and Quantum Whys, derived from the settled attunement. One of each per generation,
 * so a retried job can never store a second one.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itp_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('generation_id')->constrained('itp_generations')->cascadeOnDelete();
            $table->string('type', 16);
            $table->json('content');
            $table->unsignedInteger('from_attunement_version');
            $table->timestamps();

            $table->unique(['generation_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itp_companions');
    }
};
