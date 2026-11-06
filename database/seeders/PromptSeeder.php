<?php

namespace Database\Seeders;

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use Illuminate\Database\Seeder;

/**
 * Gives every stage, and the method core, an active v1 placeholder prompt, so the pipeline runs
 * on a fresh database. A stage that already has any version is left alone. The real prompts come
 * from `php artisan itp:import-prompts` (the committed prompt library) or Studio.
 */
class PromptSeeder extends Seeder
{
    public const string PLACEHOLDER = 'Placeholder — replace in Studio.';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (ItpStage::cases() as $stage) {
            if (PromptVersion::query()->where('stage', $stage)->exists()) {
                continue;
            }

            PromptVersion::create([
                'stage' => $stage,
                'version' => 1,
                'body' => self::PLACEHOLDER."\n\n".$stage->purpose(),
            ])->activate();
        }
    }
}
