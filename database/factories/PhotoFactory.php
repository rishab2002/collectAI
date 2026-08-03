<?php

namespace Database\Factories;

use App\Models\Photo;
use App\Models\Variation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'variation_id' => Variation::factory(),
            // 'local' rather than the column's 'r2' default: a factory shouldn't
            // produce rows pointing at a bucket the test environment can't reach.
            'disk' => 'local',
            'path' => 'photos/'.fake()->uuid().'.jpg',
            'type' => fake()->randomElement(['card_front', 'card_back', 'loose']),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
