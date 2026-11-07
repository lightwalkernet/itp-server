<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `part` is the SHAPE step (1 to 8) a line belongs to; null for attunements written before
 * prompt library v2, which count as one section, part 1.
 *
 * `proposed_by_flag_id` marks a line a section regeneration wrote and the user hasn't kept yet.
 * Kept: it is cleared and the line is current. Not kept: the line is superseded and keeps it,
 * so a superseded line with a proposing flag is a rejected proposal.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itp_attunement_segments', function (Blueprint $table) {
            $table->unsignedTinyInteger('part')->nullable()->after('order');
            $table->foreignId('proposed_by_flag_id')->nullable()->after('version')->constrained('itp_segment_flags')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('itp_attunement_segments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposed_by_flag_id');
            $table->dropColumn('part');
        });
    }
};
