<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every segment a regeneration wrote for a flag, in order: the flagged segment's replacement
 * (also `resulting_segment_id`) and, when the model had to change it too, one adjacent segment.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itp_segment_flags', function (Blueprint $table) {
            $table->json('resulting_segment_ids')->nullable()->after('resulting_segment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('itp_segment_flags', function (Blueprint $table) {
            $table->dropColumn('resulting_segment_ids');
        });
    }
};
