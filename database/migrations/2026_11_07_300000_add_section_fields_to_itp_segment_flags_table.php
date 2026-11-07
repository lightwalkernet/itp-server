<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags are on a section (`part`, 1 to 8) since prompt library v2. `segment_id` becomes the line
 * the user pointed to, if any. `choice` is the user's pick between the rewritten section and the
 * original (`new` | `original`), made at `chosen_at`. Flags from before v2 have no part.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itp_segment_flags', function (Blueprint $table) {
            $table->unsignedTinyInteger('part')->nullable()->after('generation_id');
            $table->string('choice', 8)->nullable()->after('resulting_segment_ids');
            $table->timestamp('chosen_at')->nullable()->after('choice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('itp_segment_flags', function (Blueprint $table) {
            $table->dropColumn(['part', 'choice', 'chosen_at']);
        });
    }
};
