<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The attunement of a generation. `version` goes up by one with every regenerated segment.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itp_attunements', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('generation_id')->unique()->constrained('itp_generations')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itp_attunements');
    }
};
