<?php

namespace Database\Factories;

use App\Models\PriceHistory;
use App\Models\Variation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceHistory>
 */
class PriceHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'variation_id' => Variation::factory(),
            'price' => fake()->randomFloat(2, 1, 400),
            'currency' => 'USD',
            'source' => fake()->randomElement(['eBay', 'Mercari', 'Retail', 'Collector survey']),
            'observed_at' => fake()->dateTimeBetween('-2 years'),
        ];
    }
}
