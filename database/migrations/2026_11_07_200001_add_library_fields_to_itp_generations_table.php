<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the safety verdict (its confidence and the staff-only reason), and the user's
 * corrections: each flag's explanation and requested change, folded in before the segment is
 * regenerated so later stages keep honouring it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itp_generations', function (Blueprint $table) {
            $table->decimal('safety_confidence', 4, 3)->nullable()->after('safety_categories');
            $table->text('safety_reason')->nullable()->after('safety_confidence');
            $table->json('corrections')->nullable()->after('derived_slots');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('itp_generations', function (Blueprint $table) {
            $table->dropColumn(['safety_confidence', 'safety_reason', 'corrections']);
        });
    }
};
