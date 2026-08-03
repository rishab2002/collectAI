<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserWishlistItem;
use App\Models\Variation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserWishlistItem>
 */
class UserWishlistItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'variation_id' => Variation::factory(),
        ];
    }
}
