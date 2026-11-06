<?php

namespace App\Ai;

/**
 * The USD cost of a call, from prices per million tokens (`itp.ai.prices`). A dated model id,
 * such as `claude-opus-5-5-20261101`, is priced as its alias.
 */
final class UsageCost
{
    /**
     * @param  array<string, array{input: float, output: float, cache_read: float, cache_write: float}>  $prices
     */
    public function __construct(private readonly array $prices) {}

    /**
     * The cost in USD, rounded to six places, or null when the model has no price.
     */
    public function calculate(string $model, TokenUsage $usage): ?float
    {
        $price = $this->priceFor($model);

        if ($price === null) {
            return null;
        }

        $microDollars = $usage->input * $price['input']
            + $usage->output * $price['output']
            + $usage->cacheWrite * $price['cache_write']
            + $usage->cacheRead * $price['cache_read'];

        return round($microDollars / 1_000_000, 6);
    }

    /**
     * @return array{input: float, output: float, cache_read: float, cache_write: float}|null
     */
    private function priceFor(string $model): ?array
    {
        if (isset($this->prices[$model])) {
            return $this->prices[$model];
        }

        foreach ($this->prices as $alias => $price) {
            if (str_starts_with($model, $alias.'-')) {
                return $price;
            }
        }

        return null;
    }
}
