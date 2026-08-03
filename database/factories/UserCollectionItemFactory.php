<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserCollectionItem;
use App\Models\Variation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserCollectionItem>
 */
class UserCollectionItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'variation_id' => Variation::factory(),
            'quantity' => fake()->numberBetween(1, 3),
            'condition' => fake()->randomElement(['Mint on card', 'Opened', 'Loose', 'Damaged card']),
            'acquired_at' => fake()->dateTimeBetween('-5 years'),
        ];
    }
}
