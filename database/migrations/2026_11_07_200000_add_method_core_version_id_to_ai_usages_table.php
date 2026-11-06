<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The method-core prompt version a call ran with, beside the stage's own prompt version. Null
 * for the stages that don't write in the ITP voice (safety, extraction, follow-ups, title).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->foreignId('method_core_version_id')->nullable()->after('prompt_version_id')->constrained('prompt_versions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('method_core_version_id');
        });
    }
};
