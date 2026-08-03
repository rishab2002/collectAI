<?php

namespace Database\Factories;

use App\Models\Release;
use App\Models\Variation;
use Database\Factories\Concerns\HasApprovalStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variation>
 */
class VariationFactory extends Factory
{
    use HasApprovalStates;

    public function definition(): array
    {
        return [
            'release_id' => Release::factory(),
            'finish' => fake()->randomElement(['Enamel', 'Spectraflame', 'Metalflake', 'Matte', 'Pearl']),
            'color' => fake()->randomElement(['Red', 'Blue', 'Black', 'White', 'Silver', 'Green', 'Yellow', 'Orange', 'Purple', 'Gold']),
            'rim_type' => fake()->randomElement(['5SP', 'PR5', 'MC5', 'Real Riders', '10SP', 'Y5']),
            'tampo' => fake()->randomElement(['Side stripes', 'Racing livery', 'Flame graphics', 'None', 'Sponsor decals']),
            'interior_color' => fake()->randomElement(['Black', 'Grey', 'Tan', 'Red']),
            'window_tint' => fake()->randomElement(['Clear', 'Smoke', 'Blue', 'None']),
            'base_color' => fake()->randomElement(['Black', 'Chrome', 'Grey', 'Body colour']),
            'notes' => null,
            'status' => Variation::STATUS_LIVE,
        ];
    }

    /**
     * A variation with every optional attribute left null.
     *
     * This is the case the uniqueness index exists for: under a plain UNIQUE
     * constraint two of these would both insert, because Postgres treats
     * NULL != NULL. Against the COALESCE(col, '') index the second is rejected.
     */
    public function bare(): static
    {
        return $this->state([
            'finish' => null,
            'color' => null,
            'rim_type' => null,
            'tampo' => null,
            'interior_color' => null,
            'window_tint' => null,
            'base_color' => null,
        ]);
    }
}
