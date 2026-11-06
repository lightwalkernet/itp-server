<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered lines of an attunement. A regenerated segment is never deleted: it gets
 * `superseded_at`, and its replacement takes the same `order` with the new version.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itp_attunement_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attunement_id')->constrained('itp_attunements')->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->text('text');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['attunement_id', 'superseded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itp_attunement_segments');
    }
};
