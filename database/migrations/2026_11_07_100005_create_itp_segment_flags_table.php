<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's "this doesn't resonate" on one segment. Kept as training signal: losing the
 * segment never deletes the flag. The flag goes only with its generation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itp_segment_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('generation_id')->constrained('itp_generations')->cascadeOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('itp_attunement_segments')->nullOnDelete();
            $table->text('user_explanation');
            $table->text('requested_change')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('resulting_segment_id')->nullable()->constrained('itp_attunement_segments')->nullOnDelete();
            $table->timestamps();

            $table->index('segment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itp_segment_flags');
    }
};
