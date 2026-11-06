<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's intake answers. Server-only. The personal answers are encrypted at rest
 * (platform.md §9), so they are all text columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itp_intakes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->constrained();
            $table->text('display_name');
            $table->string('address_style', 16);
            $table->text('q1_dedication');
            $table->text('q2_challenge');
            $table->text('q3_old_story');
            $table->text('q3_phrase')->nullable();
            $table->text('q3_protective')->nullable();
            $table->text('q4_vision');
            $table->text('q4_scene')->nullable();
            $table->text('q5_soulful');
            $table->text('avoid')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itp_intakes');
    }
};
