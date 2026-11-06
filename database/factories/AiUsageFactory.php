<?php

namespace Database\Factories;

use App\Enums\ItpStage;
use App\Models\AiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage' => ItpStage::Attunement,
            'model' => 'claude-opus-5-5',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cache_write_tokens' => 0,
            'cache_read_tokens' => 0,
            'cost_usd' => 0.014,
            'duration_ms' => 1200,
        ];
    }
}
