<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up questions, asked in rounds. `answered_at` closes a question, even when the user
 * skipped it (`answer_text` null). Answers are encrypted at rest, like the intake.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itp_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('generation_id')->constrained('itp_generations')->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->text('question_text');
            $table->text('answer_text')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['generation_id', 'round']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itp_followups');
    }
};
