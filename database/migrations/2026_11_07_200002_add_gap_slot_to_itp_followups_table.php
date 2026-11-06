<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The extraction slot a follow-up question was written to fill.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itp_followups', function (Blueprint $table) {
            $table->string('gap_slot', 64)->nullable()->after('round');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('itp_followups', function (Blueprint $table) {
            $table->dropColumn('gap_slot');
        });
    }
};
